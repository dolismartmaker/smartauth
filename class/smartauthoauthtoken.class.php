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
 * \file        class/smartauthoauthtoken.class.php
 * \ingroup     smartauth
 * \brief       OAuth2 Token management class
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Class for OAuth2 Token management
 */
class SmartAuthOAuthToken extends CommonObject
{
    /**
     * @var string ID of module
     */
    public $module = 'smartauth';

    /**
     * @var string ID to identify managed object
     */
    public $element = 'smartauthoauthtoken';

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'smartauth_oauth_tokens';

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
    public $picto = 'fa-key';

    /**
     * Token type constants
     */
    public const TOKEN_TYPE_ACCESS = 'access';
    public const TOKEN_TYPE_REFRESH = 'refresh';

    /**
     * @var array Array with all fields and their property
     */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1, 'comment' => 'Id'),
        'token_hash' => array('type' => 'varchar(255)', 'label' => 'TokenHash', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1, 'comment' => 'SHA256 hash of the token'),
        'token_type' => array('type' => 'varchar(20)', 'label' => 'TokenType', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'comment' => 'access or refresh'),
        'fk_client' => array('type' => 'integer', 'label' => 'Client', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'foreignkey' => 'smartauth_oauth_clients.rowid', 'comment' => 'OAuth client'),
        'fk_user' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'User', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'foreignkey' => 'user.rowid', 'comment' => 'Dolibarr user'),
        'scopes' => array('type' => 'text', 'label' => 'Scopes', 'enabled' => 1, 'position' => 50, 'notnull' => 1, 'visible' => 0, 'comment' => 'JSON array of granted scopes'),
        'jti' => array('type' => 'varchar(64)', 'label' => 'JTI', 'enabled' => 1, 'position' => 60, 'notnull' => 0, 'visible' => 0, 'index' => 1, 'comment' => 'JWT ID for access tokens'),
        'expires_at' => array('type' => 'datetime', 'label' => 'ExpiresAt', 'enabled' => 1, 'position' => 70, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'comment' => 'Expiration time'),
        'revoked_at' => array('type' => 'datetime', 'label' => 'RevokedAt', 'enabled' => 1, 'position' => 80, 'notnull' => 0, 'visible' => 1, 'comment' => 'Revocation time'),
        'fk_parent' => array('type' => 'integer', 'label' => 'Parent', 'enabled' => 1, 'position' => 90, 'notnull' => 0, 'visible' => 0, 'index' => 1, 'comment' => 'Parent token (refresh -> access)'),
        'ip_address' => array('type' => 'varchar(45)', 'label' => 'IPAddress', 'enabled' => 1, 'position' => 100, 'notnull' => 0, 'visible' => 0, 'comment' => 'IP address at creation'),
        'user_agent' => array('type' => 'varchar(512)', 'label' => 'UserAgent', 'enabled' => 1, 'position' => 110, 'notnull' => 0, 'visible' => 0, 'comment' => 'User-Agent at creation'),
        'datec' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 120, 'notnull' => 1, 'visible' => 0, 'comment' => 'Creation date'),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 130, 'notnull' => 1, 'visible' => 0, 'default' => 1, 'index' => 1, 'comment' => 'Multi-company entity'),
    );

    /**
     * @var int Object ID
     */
    public $id;

    /**
     * @var string SHA256 hash of the token
     */
    public $token_hash;

    /**
     * @var string Token type (access or refresh)
     */
    public $token_type;

    /**
     * @var int OAuth client ID
     */
    public $fk_client;

    /**
     * @var int Dolibarr user ID
     */
    public $fk_user;

    /**
     * @var string JSON array of scopes
     */
    public $scopes;

    /**
     * @var string|null JWT ID
     */
    public $jti;

    /**
     * @var int|string Expiration time
     */
    public $expires_at;

    /**
     * @var int|string|null Revocation time
     */
    public $revoked_at;

    /**
     * @var int|null Parent token ID
     */
    public $fk_parent;

    /**
     * @var string|null IP address
     */
    public $ip_address;

    /**
     * @var string|null User-Agent
     */
    public $user_agent;

    /**
     * @var int|string Creation date
     */
    public $datec;

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
     * Generate a new refresh token
     *
     * @return string Plain text token (format: smartauth_rt_XXXX)
     */
    public static function generateRefreshToken()
    {
        return 'smartauth_rt_' . bin2hex(random_bytes(32));
    }

    /**
     * Generate a unique JWT ID
     *
     * @return string JWT ID
     */
    public static function generateJti()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Hash a token for storage
     *
     * @param string $token Plain text token
     * @return string SHA256 hash of the token
     */
    public static function hashToken($token)
    {
        return hash('sha256', $token);
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

        if (empty($this->datec)) {
            $this->datec = dol_now();
        }
        if (empty($this->entity)) {
            $this->entity = $conf->entity;
        }

        // Ensure scopes is JSON
        if (is_array($this->scopes)) {
            $this->scopes = json_encode($this->scopes);
        }

        // Capture IP and User-Agent if not set
        if (empty($this->ip_address) && !empty($_SERVER['REMOTE_ADDR'])) {
            $this->ip_address = $_SERVER['REMOTE_ADDR'];
        }
        if (empty($this->user_agent) && !empty($_SERVER['HTTP_USER_AGENT'])) {
            $this->user_agent = substr($_SERVER['HTTP_USER_AGENT'], 0, 512);
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
     * Load object by token hash
     *
     * @param string $tokenHash SHA256 hash of the token
     * @return int              <0 if KO, 0 if not found, >0 if OK
     */
    public function fetchByTokenHash($tokenHash)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE token_hash = '" . $this->db->escape($tokenHash) . "'";
        $sql .= " AND entity IN (" . getEntity($this->element) . ")";

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
     * Load object by plain text token
     *
     * @param string $token Plain text token
     * @return int          <0 if KO, 0 if not found, >0 if OK
     */
    public function fetchByToken($token)
    {
        return $this->fetchByTokenHash(self::hashToken($token));
    }

    /**
     * Load object by JTI
     *
     * @param string $jti JWT ID
     * @return int        <0 if KO, 0 if not found, >0 if OK
     */
    public function fetchByJti($jti)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE jti = '" . $this->db->escape($jti) . "'";
        $sql .= " AND entity IN (" . getEntity($this->element) . ")";

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
     * Load list of objects in memory from the database
     *
     * @param string $sortorder  Sort Order
     * @param string $sortfield  Sort field
     * @param int    $limit      Limit
     * @param int    $offset     Offset
     * @param array  $filter     Filter array
     * @param string $filtermode Filter mode (AND or OR)
     * @return array|int         int <0 if KO, array of objects if OK
     */
    public function fetchAll($sortorder = '', $sortfield = '', $limit = 0, $offset = 0, array $filter = array(), $filtermode = 'AND')
    {
        dol_syslog(__METHOD__, LOG_DEBUG);

        $records = array();

        $sql = "SELECT ";
        $sql .= $this->getFieldList('t');
        $sql .= " FROM " . $this->db->prefix() . $this->table_element . " as t";
        $sql .= " WHERE t.entity IN (" . getEntity($this->element) . ")";

        // Manage filter
        $sqlwhere = array();
        if (count($filter) > 0) {
            foreach ($filter as $key => $value) {
                if ($key == 't.rowid') {
                    $sqlwhere[] = $key . " = " . ((int) $value);
                } elseif ($key == 'customsql') {
                    $sqlwhere[] = $value;
                } elseif (strpos($value, '%') === false) {
                    $sqlwhere[] = $key . " IN (" . $this->db->sanitize($this->db->escape($value)) . ")";
                } else {
                    $sqlwhere[] = $key . " LIKE '%" . $this->db->escapeforlike($this->db->escape($value)) . "%'";
                }
            }
        }
        if (count($sqlwhere) > 0) {
            $sql .= " AND (" . implode(" " . $filtermode . " ", $sqlwhere) . ")";
        }

        if (!empty($sortfield)) {
            $sql .= $this->db->order($sortfield, $sortorder);
        }
        if (!empty($limit)) {
            $sql .= $this->db->plimit($limit, $offset);
        }

        $resql = $this->db->query($sql);
        if ($resql) {
            $num = $this->db->num_rows($resql);
            $i = 0;
            while ($i < ($limit ? min($limit, $num) : $num)) {
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
            dol_syslog(__METHOD__ . ' ' . join(',', $this->errors), LOG_ERR);

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
     * Revoke this token
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
     * Revoke this token and all its children (cascade revocation)
     *
     * @return int Number of revoked tokens or -1 if error
     */
    public function revokeWithChildren()
    {
        $count = 0;

        // First revoke children
        $childCount = $this->revokeChildren();
        if ($childCount < 0) {
            return -1;
        }
        $count += $childCount;

        // Then revoke self
        $result = $this->revoke();
        if ($result > 0) {
            $count++;
        } else {
            return -1;
        }

        return $count;
    }

    /**
     * Revoke all child tokens (tokens created from this refresh token)
     *
     * @return int Number of revoked tokens or -1 if error
     */
    public function revokeChildren()
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " SET revoked_at = '" . $this->db->idate(dol_now()) . "'";
        $sql .= " WHERE fk_parent = " . ((int) $this->id);
        $sql .= " AND revoked_at IS NULL";

        $resql = $this->db->query($sql);
        if ($resql) {
            return $this->db->affected_rows($resql);
        }

        $this->error = $this->db->lasterror();
        return -1;
    }

    /**
     * Check if token is revoked
     *
     * @return bool True if revoked
     */
    public function isRevoked()
    {
        return !empty($this->revoked_at);
    }

    /**
     * Check if token is expired
     *
     * @return bool True if expired
     */
    public function isExpired()
    {
        if (empty($this->expires_at)) {
            return true;
        }
        $expiresAt = is_numeric($this->expires_at) ? $this->expires_at : strtotime($this->expires_at);
        return $expiresAt < dol_now();
    }

    /**
     * Check if token is valid (not expired and not revoked)
     *
     * @return bool True if valid
     */
    public function isValid()
    {
        return !$this->isExpired() && !$this->isRevoked();
    }

    /**
     * Check if this is an access token
     *
     * @return bool True if access token
     */
    public function isAccessToken()
    {
        return $this->token_type === self::TOKEN_TYPE_ACCESS;
    }

    /**
     * Check if this is a refresh token
     *
     * @return bool True if refresh token
     */
    public function isRefreshToken()
    {
        return $this->token_type === self::TOKEN_TYPE_REFRESH;
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
        $this->scopes = json_encode(array_values($scopes));
    }

    /**
     * Revoke all tokens for a specific user and client
     *
     * @param DoliDB $db       Database handler
     * @param int    $userId   User ID
     * @param int    $clientId Client ID
     * @return int             Number of revoked tokens or -1 if error
     */
    public static function revokeAllForUserAndClient(DoliDB $db, $userId, $clientId)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " SET revoked_at = '" . $db->idate(dol_now()) . "'";
        $sql .= " WHERE fk_user = " . ((int) $userId);
        $sql .= " AND fk_client = " . ((int) $clientId);
        $sql .= " AND revoked_at IS NULL";

        $resql = $db->query($sql);
        if ($resql) {
            return $db->affected_rows($resql);
        }

        return -1;
    }

    /**
     * Revoke all tokens for a specific user
     *
     * @param DoliDB $db     Database handler
     * @param int    $userId User ID
     * @return int           Number of revoked tokens or -1 if error
     */
    public static function revokeAllForUser(DoliDB $db, $userId)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
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
     * Delete all expired and revoked tokens older than specified time
     *
     * @param DoliDB $db      Database handler
     * @param int    $seconds Minimum age in seconds (default 7 days)
     * @return int            Number of deleted tokens or -1 if error
     */
    public static function deleteExpired(DoliDB $db, $seconds = 604800)
    {
        $cutoff = dol_now() - $seconds;

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_oauth_tokens";
        $sql .= " WHERE (expires_at < '" . $db->idate(dol_now()) . "'";
        $sql .= " OR revoked_at IS NOT NULL)";
        $sql .= " AND datec < '" . $db->idate($cutoff) . "'";

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
        $this->token_hash = self::hashToken('specimen-token-12345');
        $this->token_type = self::TOKEN_TYPE_ACCESS;
        $this->fk_client = 1;
        $this->fk_user = 1;
        $this->scopes = json_encode(array('openid', 'profile'));
        $this->jti = self::generateJti();
        $this->expires_at = dol_now() + 3600;
    }
}
