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
 * \file        class/smartauthoauthcode.class.php
 * \ingroup     smartauth
 * \brief       OAuth2 Authorization Code management class
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Class for OAuth2 Authorization Code management
 */
class SmartAuthOAuthCode extends CommonObject
{
    /**
     * @var string ID of module
     */
    public $module = 'smartauth';

    /**
     * @var string ID to identify managed object
     */
    public $element = 'smartauthoauthcode';

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'smartauth_oauth_codes';

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
    public $picto = 'fa-ticket';

    /**
     * Default code lifetime in seconds (10 minutes)
     */
    public const CODE_LIFETIME = 600;

    /**
     * @var array Array with all fields and their property
     */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1, 'comment' => 'Id'),
        'code_hash' => array('type' => 'varchar(255)', 'label' => 'CodeHash', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1, 'comment' => 'SHA256 hash of the authorization code'),
        'fk_client' => array('type' => 'integer', 'label' => 'Client', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1, 'index' => 1, 'foreignkey' => 'smartauth_oauth_clients.rowid', 'comment' => 'OAuth client'),
        // Plain integer (not an object-link type): an account subject stores
        // the sentinel 0 here, and Dolibarr's createCommon must write 0 as-is
        // rather than nullifying an "empty" object FK (the column is NOT NULL).
        'fk_user' => array('type' => 'integer', 'label' => 'User', 'enabled' => 1, 'position' => 30, 'notnull' => 1, 'default' => 0, 'visible' => 1, 'index' => 1, 'comment' => 'Dolibarr user (0 when subject_type=account)'),
        'subject_type' => array('type' => 'varchar(16)', 'label' => 'SubjectType', 'enabled' => 1, 'position' => 31, 'notnull' => 1, 'visible' => 0, 'default' => 'user', 'comment' => 'Code subject kind: user or account'),
        'fk_societe_account' => array('type' => 'integer', 'label' => 'SocieteAccount', 'enabled' => 1, 'position' => 32, 'notnull' => 0, 'visible' => 0, 'index' => 1, 'comment' => 'Portal account rowid when subject_type=account'),
        'fk_adherent' => array('type' => 'integer', 'label' => 'Adherent', 'enabled' => 1, 'position' => 33, 'notnull' => 0, 'visible' => 0, 'index' => 1, 'comment' => 'Adherent rowid when subject_type=member'),
        'redirect_uri' => array('type' => 'varchar(2048)', 'label' => 'RedirectURI', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 0, 'comment' => 'Callback URI'),
        'scopes' => array('type' => 'text', 'label' => 'Scopes', 'enabled' => 1, 'position' => 50, 'notnull' => 1, 'visible' => 0, 'comment' => 'JSON array of requested scopes'),
        'state' => array('type' => 'varchar(255)', 'label' => 'State', 'enabled' => 1, 'position' => 60, 'notnull' => 0, 'visible' => 0, 'comment' => 'Original state parameter'),
        'nonce' => array('type' => 'varchar(255)', 'label' => 'Nonce', 'enabled' => 1, 'position' => 65, 'notnull' => 0, 'visible' => 0, 'comment' => 'OIDC nonce for id_token'),
        'code_challenge' => array('type' => 'varchar(128)', 'label' => 'CodeChallenge', 'enabled' => 1, 'position' => 70, 'notnull' => 0, 'visible' => 0, 'comment' => 'PKCE code challenge'),
        'code_challenge_method' => array('type' => 'varchar(10)', 'label' => 'CodeChallengeMethod', 'enabled' => 1, 'position' => 80, 'notnull' => 0, 'visible' => 0, 'comment' => 'PKCE method (S256 or plain)'),
        'expires_at' => array('type' => 'datetime', 'label' => 'ExpiresAt', 'enabled' => 1, 'position' => 90, 'notnull' => 1, 'visible' => 1, 'comment' => 'Expiration time'),
        'used_at' => array('type' => 'datetime', 'label' => 'UsedAt', 'enabled' => 1, 'position' => 100, 'notnull' => 0, 'visible' => 1, 'comment' => 'Time when code was used'),
        'datec' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 110, 'notnull' => 1, 'visible' => 0, 'comment' => 'Creation date'),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 120, 'notnull' => 1, 'visible' => 0, 'default' => 1, 'index' => 1, 'comment' => 'Multi-company entity'),
    );

    /**
     * @var int Object ID
     */
    public $id;

    /**
     * @var string SHA256 hash of the authorization code
     */
    public $code_hash;

    /**
     * @var int OAuth client ID
     */
    public $fk_client;

    /**
     * @var int Dolibarr user ID (0 when subject_type=account)
     */
    public $fk_user;

    /**
     * @var string Code subject kind: 'user', 'account' or 'member'
     */
    public $subject_type = 'user';

    /**
     * @var int|null Portal account rowid (llx_societe_account) when subject_type=account
     */
    public $fk_societe_account = null;

    /**
     * @var int|null Adherent rowid (llx_adherent) when subject_type=member
     */
    public $fk_adherent = null;

    /**
     * @var string Callback URI
     */
    public $redirect_uri;

    /**
     * @var string JSON array of scopes
     */
    public $scopes;

    /**
     * @var string|null Original state parameter
     */
    public $state;

    /**
     * @var string|null OIDC nonce
     */
    public $nonce;

    /**
     * @var string|null PKCE code challenge
     */
    public $code_challenge;

    /**
     * @var string|null PKCE method
     */
    public $code_challenge_method;

    /**
     * @var int|string Expiration time
     */
    public $expires_at;

    /**
     * @var int|string|null Time when code was used
     */
    public $used_at;

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
     * Generate a new authorization code
     *
     * @return string Plain text code (to be returned to client)
     */
    public static function generateCode()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Hash a code for storage
     *
     * @param string $code Plain text code
     * @return string SHA256 hash of the code
     */
    public static function hashCode($code)
    {
        return hash('sha256', $code);
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
        if (empty($this->expires_at)) {
            $this->expires_at = dol_now() + self::CODE_LIFETIME;
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
     * Load object by code hash
     *
     * @param string $codeHash SHA256 hash of the code
     * @return int             <0 if KO, 0 if not found, >0 if OK
     */
    public function fetchByCodeHash($codeHash)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " WHERE code_hash = '" . $this->db->escape($codeHash) . "'";
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
     * Load object by plain text code
     *
     * @param string $code Plain text authorization code
     * @return int         <0 if KO, 0 if not found, >0 if OK
     */
    public function fetchByCode($code)
    {
        return $this->fetchByCodeHash(self::hashCode($code));
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
     * Mark code as used
     *
     * @return int <0 if KO, >0 if OK
     */
    public function markAsUsed()
    {
        $this->used_at = dol_now();

        $sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_element;
        $sql .= " SET used_at = '" . $this->db->idate($this->used_at) . "'";
        $sql .= " WHERE rowid = " . ((int) $this->id);

        $resql = $this->db->query($sql);
        if ($resql) {
            return 1;
        }

        $this->error = $this->db->lasterror();
        return -1;
    }

    /**
     * Check if code is expired
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
     * Check if code has been used
     *
     * @return bool True if used
     */
    public function isUsed()
    {
        return !empty($this->used_at);
    }

    /**
     * Check if code is valid (not expired and not used)
     *
     * @return bool True if valid
     */
    public function isValid()
    {
        return !$this->isExpired() && !$this->isUsed();
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
     * Verify PKCE code verifier
     *
     * @param string $codeVerifier The code verifier to check
     * @return bool True if valid
     */
    public function verifyPkce($codeVerifier)
    {
        if (empty($this->code_challenge)) {
            // No PKCE was used
            return true;
        }

        // Only S256 is supported. The legacy 'plain' method (and any unknown
        // method) is rejected as part of the CR-3 fix in TODO-SECURITY-01:
        // plain offers no protection against code interception attacks.
        if ($this->code_challenge_method !== 'S256') {
            dol_syslog('SmartAuthOAuthCode::verifyPkce - unsupported method: ' . ($this->code_challenge_method ?? '(null)'), LOG_WARNING);
            return false;
        }

        $expectedChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
        return hash_equals($this->code_challenge, $expectedChallenge);
    }

    /**
     * Delete all expired codes
     *
     * @param DoliDB $db Database handler
     * @return int       Number of deleted codes or -1 if error
     */
    public static function deleteExpired(DoliDB $db)
    {
        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_oauth_codes";
        $sql .= " WHERE expires_at < '" . $db->idate(dol_now()) . "'";

        $resql = $db->query($sql);
        if ($resql) {
            return $db->affected_rows($resql);
        }

        return -1;
    }

    /**
     * Delete all used codes older than specified time
     *
     * @param DoliDB $db      Database handler
     * @param int    $seconds Minimum age in seconds (default 1 hour)
     * @return int            Number of deleted codes or -1 if error
     */
    public static function deleteUsed(DoliDB $db, $seconds = 3600)
    {
        $cutoff = dol_now() - $seconds;

        $sql = "DELETE FROM " . MAIN_DB_PREFIX . "smartauth_oauth_codes";
        $sql .= " WHERE used_at IS NOT NULL";
        $sql .= " AND used_at < '" . $db->idate($cutoff) . "'";

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
        $this->code_hash = self::hashCode('specimen-code-12345');
        $this->fk_client = 1;
        $this->fk_user = 1;
        $this->redirect_uri = 'https://example.com/callback';
        $this->scopes = json_encode(array('openid', 'profile'));
        $this->expires_at = dol_now() + self::CODE_LIFETIME;
    }
}
