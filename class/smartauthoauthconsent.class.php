<?php
/* Copyright (C) 2024 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file        class/smartauthoauthconsent.class.php
 * \ingroup     smartauth
 * \brief       OAuth2 Consent management class
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Class for OAuth2 Consent management
 */
class SmartAuthOAuthConsent extends CommonObject
{
    /**
     * @var string ID of module
     */
    public $module = 'smartauth';

    /**
     * @var string ID to identify managed object
     */
    public $element = 'smartauthoauthconsent';

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'smartauth_oauth_consents';

    /**
     * @var int Does this object support multicompany module?
     */
    public $ismultientitymanaged = 1;

    /**
     * @var int Does object support extrafields?
     */
    public $isextrafieldmanaged = 0;

    /**
     * @var string String with name of icon
     */
    public $picto = 'fa-check-circle';

    /**
     * @var array Array with all fields and their property
     */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1, 'comment' => 'Id'),
        'fk_client' => array('type' => 'integer', 'label' => 'Client', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'foreignkey' => 'smartauth_oauth_clients.rowid', 'comment' => 'OAuth client'),
        'fk_user' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'User', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'foreignkey' => 'user.rowid', 'comment' => 'Dolibarr user'),
        'scopes' => array('type' => 'text', 'label' => 'Scopes', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'visible' => 1, 'comment' => 'JSON array of consented scopes'),
        'granted_at' => array('type' => 'datetime', 'label' => 'GrantedAt', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 1, 'comment' => 'Consent grant time'),
        'revoked_at' => array('type' => 'datetime', 'label' => 'RevokedAt', 'enabled' => 1, 'position' => 50, 'notnull' => 0, 'visible' => 1, 'comment' => 'Revocation time'),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 60, 'notnull' => 1, 'visible' => 0, 'default' => 1, 'index' => 1, 'comment' => 'Multi-company entity'),
    );

    /**
     * @var int Object ID
     */
    public $id;

    /**
     * @var int OAuth client ID
     */
    public $fk_client;

    /**
     * @var int Dolibarr user ID
     */
    public $fk_user;

    /**
     * @var string JSON array of consented scopes
     */
    public $scopes;

    /**
     * @var int|string Consent grant time
     */
    public $granted_at;

    /**
     * @var int|string|null Revocation time
     */
    public $revoked_at;

    /**
     * @var int Entity
     */
    public $entity;

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct(DoliDB $db)
    {
        global $conf;

        $this->db = $db;

        if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
            $this->fields['entity']['enabled'] = 0;
        }

        // Unset fields that are disabled
        foreach ($this->fields as $key => $val) {
            if (isset($val['enabled']) && empty($val['enabled'])) {
                unset($this->fields[$key]);
            }
        }
    }

    /**
     * Create object into database
     *
     * @param User $user      User that creates
     * @param bool $notrigger false=launch triggers after, true=disable triggers
     * @return int            <0 if KO, Id of created object if OK
     */
    public function create(User $user, $notrigger = false)
    {
        global $conf;

        if (empty($this->granted_at)) {
            $this->granted_at = dol_now();
        }
        if (empty($this->entity)) {
            $this->entity = $conf->entity;
        }

        // Ensure scopes is JSON
        if (is_array($this->scopes)) {
            $this->scopes = json_encode($this->scopes);
        }

        return $this->createCommon($user, $notrigger);
    }

    /**
     * Load object in memory from the database by ID
     *
     * @param int    $id  Id object
     * @param string $ref Ref (not used)
     * @return int        <0 if KO, 0 if not found, >0 if OK
     */
    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
    }

    /**
     * Load consent by client and user
     *
     * @param int $clientId Client ID
     * @param int $userId   User ID
     * @return int          <0 if KO, 0 if not found, >0 if OK
     */
    public function fetchByClientAndUser($clientId, $userId)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE fk_client = " . ((int) $clientId);
        $sql .= " AND fk_user = " . ((int) $userId);
        $sql .= " AND entity IN (" . getEntity($this->element) . ")";
        $sql .= " AND revoked_at IS NULL";
        $sql .= " ORDER BY granted_at DESC";
        $sql .= " LIMIT 1";

        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if ($obj) {
                return $this->fetch($obj->rowid);
            }
            return 0;
        }
        return -1;
    }

    /**
     * Load all consents for a user
     *
     * @param int  $userId       User ID
     * @param bool $activeOnly   If true, only return non-revoked consents
     * @return array|int         Array of consents or -1 if error
     */
    public function fetchAllForUser($userId, $activeOnly = true)
    {
        $records = array();

        $sql = "SELECT ";
        $sql .= $this->getFieldList('t');
        $sql .= " FROM " . $this->db->prefix() . $this->table_element . " as t";
        $sql .= " WHERE t.fk_user = " . ((int) $userId);
        $sql .= " AND t.entity IN (" . getEntity($this->element) . ")";
        if ($activeOnly) {
            $sql .= " AND t.revoked_at IS NULL";
        }
        $sql .= " ORDER BY t.granted_at DESC";

        $resql = $this->db->query($sql);
        if ($resql) {
            $num = $this->db->num_rows($resql);
            $i = 0;
            while ($i < $num) {
                $obj = $this->db->fetch_object($resql);

                $record = new self($this->db);
                $record->setVarsFromFetchObj($obj);

                $records[$record->id] = $record;

                $i++;
            }
            $this->db->free($resql);

            return $records;
        } else {
            $this->errors[] = 'Error ' . $this->db->lasterror();
            dol_syslog("SmartAuth ".__METHOD__ . ' ' . join(',', $this->errors), LOG_ERR);

            return -1;
        }
    }

    /**
     * Load all consents for a client
     *
     * @param int  $clientId     Client ID
     * @param bool $activeOnly   If true, only return non-revoked consents
     * @return array|int         Array of consents or -1 if error
     */
    public function fetchAllForClient($clientId, $activeOnly = true)
    {
        $records = array();

        $sql = "SELECT ";
        $sql .= $this->getFieldList('t');
        $sql .= " FROM " . $this->db->prefix() . $this->table_element . " as t";
        $sql .= " WHERE t.fk_client = " . ((int) $clientId);
        $sql .= " AND t.entity IN (" . getEntity($this->element) . ")";
        if ($activeOnly) {
            $sql .= " AND t.revoked_at IS NULL";
        }
        $sql .= " ORDER BY t.granted_at DESC";

        $resql = $this->db->query($sql);
        if ($resql) {
            $num = $this->db->num_rows($resql);
            $i = 0;
            while ($i < $num) {
                $obj = $this->db->fetch_object($resql);

                $record = new self($this->db);
                $record->setVarsFromFetchObj($obj);

                $records[$record->id] = $record;

                $i++;
            }
            $this->db->free($resql);

            return $records;
        } else {
            $this->errors[] = 'Error ' . $this->db->lasterror();
            dol_syslog("SmartAuth ".__METHOD__ . ' ' . join(',', $this->errors), LOG_ERR);

            return -1;
        }
    }

    /**
     * Update object into database
     *
     * @param User $user      User that modifies
     * @param bool $notrigger false=launch triggers after, true=disable triggers
     * @return int            <0 if KO, >0 if OK
     */
    public function update(User $user, $notrigger = false)
    {
        // Ensure scopes is JSON
        if (is_array($this->scopes)) {
            $this->scopes = json_encode($this->scopes);
        }

        return $this->updateCommon($user, $notrigger);
    }

    /**
     * Delete object in database
     *
     * @param User $user      User that deletes
     * @param bool $notrigger false=launch triggers, true=disable triggers
     * @return int            <0 if KO, >0 if OK
     */
    public function delete(User $user, $notrigger = false)
    {
        return $this->deleteCommon($user, $notrigger);
    }

    /**
     * Revoke this consent
     *
     * @return int <0 if KO, >0 if OK
     */
    public function revoke()
    {
        $this->revoked_at = dol_now();

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " SET revoked_at = '" . $this->db->idate($this->revoked_at) . "'";
        $sql .= " WHERE rowid = " . ((int) $this->id);

        $resql = $this->db->query($sql);
        if ($resql) {
            return 1;
        }

        $this->error = $this->db->lasterror();
        return -1;
    }

    /**
     * Check if consent is revoked
     *
     * @return bool True if revoked
     */
    public function isRevoked()
    {
        return !empty($this->revoked_at);
    }

    /**
     * Check if consent is active (not revoked)
     *
     * @return bool True if active
     */
    public function isActive()
    {
        return !$this->isRevoked();
    }

    /**
     * Get scopes as array
     *
     * @return array Array of scopes
     */
    public function getScopesArray()
    {
        if (empty($this->scopes)) {
            return array();
        }
        if (is_array($this->scopes)) {
            return $this->scopes;
        }
        $decoded = json_decode($this->scopes, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Set scopes from array
     *
     * @param array $scopes Array of scopes
     * @return void
     */
    public function setScopesArray(array $scopes)
    {
        $this->scopes = json_encode(array_values(array_unique($scopes)));
    }

    /**
     * Check if a specific scope is consented
     *
     * @param string $scope Scope to check
     * @return bool True if scope is consented
     */
    public function hasScope($scope)
    {
        $scopes = $this->getScopesArray();
        return in_array($scope, $scopes, true);
    }

    /**
     * Check if all specified scopes are consented
     *
     * @param array $scopes Array of scopes to check
     * @return bool True if all scopes are consented
     */
    public function hasAllScopes(array $scopes)
    {
        $consentedScopes = $this->getScopesArray();
        foreach ($scopes as $scope) {
            if (!in_array($scope, $consentedScopes, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Add scopes to existing consent
     *
     * @param array $scopes Array of scopes to add
     * @param User  $user   User making the change
     * @return int          <0 if KO, >0 if OK
     */
    public function addScopes(array $scopes, User $user)
    {
        $currentScopes = $this->getScopesArray();
        $newScopes = array_unique(array_merge($currentScopes, $scopes));

        // Only update if there are actually new scopes
        if (count($newScopes) > count($currentScopes)) {
            $this->setScopesArray($newScopes);
            $this->granted_at = dol_now(); // Update grant time when adding scopes
            return $this->update($user);
        }

        return 1;
    }

    /**
     * Find or create consent for client and user
     *
     * @param int   $clientId Client ID
     * @param int   $userId   User ID
     * @param array $scopes   Array of scopes
     * @param User  $user     User making the action
     * @return int            <0 if KO, >0 if OK (returns consent ID)
     */
    public function findOrCreate($clientId, $userId, array $scopes, User $user)
    {
        // Try to find existing active consent
        $result = $this->fetchByClientAndUser($clientId, $userId);

        if ($result > 0) {
            // Consent exists, add any new scopes
            $addResult = $this->addScopes($scopes, $user);
            return $addResult > 0 ? $this->id : $addResult;
        } elseif ($result === 0) {
            // No consent exists, create new
            $this->fk_client = $clientId;
            $this->fk_user = $userId;
            $this->setScopesArray($scopes);

            return $this->create($user);
        }

        // Error occurred
        return $result;
    }

    /**
     * Revoke all consents for a specific user
     *
     * @param DoliDB $db     Database handler
     * @param int    $userId User ID
     * @return int           Number of revoked consents or -1 if error
     */
    public static function revokeAllForUser(DoliDB $db, $userId)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_consents";
        $sql .= " SET revoked_at = '" . $db->idate(dol_now()) . "'";
        $sql .= " WHERE fk_user = " . ((int) $userId);
        $sql .= " AND revoked_at IS NULL";

        $resql = $db->query($sql);
        if ($resql) {
            return $db->affected_rows($resql);
        }

        return -1;
    }

    /**
     * Revoke all consents for a specific client
     *
     * @param DoliDB $db       Database handler
     * @param int    $clientId Client ID
     * @return int             Number of revoked consents or -1 if error
     */
    public static function revokeAllForClient(DoliDB $db, $clientId)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_consents";
        $sql .= " SET revoked_at = '" . $db->idate(dol_now()) . "'";
        $sql .= " WHERE fk_client = " . ((int) $clientId);
        $sql .= " AND revoked_at IS NULL";

        $resql = $db->query($sql);
        if ($resql) {
            return $db->affected_rows($resql);
        }

        return -1;
    }

    /**
     * Delete old revoked consents
     *
     * @param DoliDB $db      Database handler
     * @param int    $seconds Minimum age in seconds (default 90 days)
     * @return int            Number of deleted consents or -1 if error
     */
    public static function deleteOldRevoked(DoliDB $db, $seconds = 7776000)
    {
        $cutoff = dol_now() - $seconds;

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_oauth_consents";
        $sql .= " WHERE revoked_at IS NOT NULL";
        $sql .= " AND revoked_at < '" . $db->idate($cutoff) . "'";

        $resql = $db->query($sql);
        if ($resql) {
            return $db->affected_rows($resql);
        }

        return -1;
    }

    /**
     * Initialise object with example values
     *
     * @return void
     */
    public function initAsSpecimen()
    {
        $this->initAsSpecimenCommon();
        $this->fk_client = 1;
        $this->fk_user = 1;
        $this->scopes = json_encode(array('openid', 'profile', 'email'));
        $this->granted_at = dol_now();
    }
}
