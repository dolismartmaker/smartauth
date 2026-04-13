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
 * \file        class/smartauthoauthclient.class.php
 * \ingroup     smartauth
 * \brief       OAuth2 Client management class
 */

require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

/**
 * Class for OAuth2 Client management
 */
class SmartAuthOAuthClient extends CommonObject
{
    /**
     * @var string ID of module
     */
    public $module = 'smartauth';

    /**
     * @var string ID to identify managed object
     */
    public $element = 'smartauthoauthclient';

    /**
     * @var string Name of table without prefix where object is stored
     */
    public $table_element = 'smartauth_oauth_clients';

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
     * Status constants
     */
    public const STATUS_DISABLED = 0;
    public const STATUS_ENABLED = 1;

    /**
     * @var array Array with all fields and their property
     */
    public $fields = array(
        'rowid' => array('type' => 'integer', 'label' => 'TechnicalID', 'enabled' => 1, 'position' => 1, 'notnull' => 1, 'visible' => 0, 'noteditable' => 1, 'index' => 1, 'css' => 'left', 'comment' => 'Id'),
        'ref' => array('type' => 'varchar(128)', 'label' => 'Ref', 'enabled' => 1, 'position' => 10, 'notnull' => 1, 'visible' => 1, 'noteditable' => 0, 'index' => 1, 'searchall' => 1, 'showoncombobox' => 1, 'comment' => 'Reference of object'),
        'client_id' => array('type' => 'varchar(80)', 'label' => 'ClientID', 'enabled' => 1, 'position' => 20, 'notnull' => 1, 'visible' => 1, 'noteditable' => 1, 'index' => 1, 'searchall' => 1, 'comment' => 'Public client identifier'),
        'client_secret' => array('type' => 'varchar(255)', 'label' => 'ClientSecret', 'enabled' => 1, 'position' => 30, 'notnull' => 0, 'visible' => 0, 'noteditable' => 1, 'comment' => 'Hashed client secret'),
        'name' => array('type' => 'varchar(255)', 'label' => 'Name', 'enabled' => 1, 'position' => 40, 'notnull' => 1, 'visible' => 1, 'searchall' => 1, 'css' => 'minwidth200', 'comment' => 'Display name'),
        'description' => array('type' => 'text', 'label' => 'Description', 'enabled' => 1, 'position' => 50, 'notnull' => 0, 'visible' => 3, 'comment' => 'Description'),
        'logo_url' => array('type' => 'varchar(2048)', 'label' => 'LogoURL', 'enabled' => 1, 'position' => 60, 'notnull' => 0, 'visible' => 3, 'comment' => 'Logo URL for consent page'),
        'redirect_uris' => array('type' => 'text', 'label' => 'RedirectURIs', 'enabled' => 1, 'position' => 70, 'notnull' => 1, 'visible' => 3, 'comment' => 'JSON array of allowed redirect URIs'),
        'allowed_scopes' => array('type' => 'text', 'label' => 'AllowedScopes', 'enabled' => 1, 'position' => 80, 'notnull' => 1, 'visible' => 3, 'comment' => 'JSON array of allowed scopes'),
        'allowed_grants' => array('type' => 'text', 'label' => 'AllowedGrants', 'enabled' => 1, 'position' => 90, 'notnull' => 1, 'visible' => 3, 'comment' => 'JSON array of allowed grant types'),
        'is_confidential' => array('type' => 'integer', 'label' => 'IsConfidential', 'enabled' => 1, 'position' => 100, 'notnull' => 1, 'visible' => 1, 'default' => 1, 'comment' => '1=confidential (has secret), 0=public (PKCE)'),
        'require_pkce' => array('type' => 'integer', 'label' => 'RequirePKCE', 'enabled' => 1, 'position' => 110, 'notnull' => 1, 'visible' => 1, 'default' => 0, 'comment' => '1=PKCE required'),
        'access_token_lifetime' => array('type' => 'integer', 'label' => 'AccessTokenLifetime', 'enabled' => 1, 'position' => 120, 'notnull' => 1, 'visible' => 3, 'default' => 3600, 'comment' => 'Access token lifetime in seconds'),
        'refresh_token_lifetime' => array('type' => 'integer', 'label' => 'RefreshTokenLifetime', 'enabled' => 1, 'position' => 130, 'notnull' => 1, 'visible' => 3, 'default' => 2592000, 'comment' => 'Refresh token lifetime in seconds (30 days)'),
        'fk_service_user' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'SmartAuthServiceUser', 'enabled' => 1, 'position' => 135, 'notnull' => 0, 'visible' => 3, 'foreignkey' => 'user.rowid', 'comment' => 'Service user for client_credentials grant (M2M)'),
        'status' => array('type' => 'integer', 'label' => 'Status', 'enabled' => 1, 'position' => 500, 'notnull' => 1, 'visible' => 1, 'default' => 1, 'index' => 1, 'arrayofkeyval' => array(0 => 'Disabled', 1 => 'Enabled')),
        'fk_user_author' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserAuthor', 'enabled' => 1, 'position' => 510, 'notnull' => 0, 'visible' => 0, 'foreignkey' => 'user.rowid', 'comment' => 'User who created'),
        'fk_user_modif' => array('type' => 'integer:User:user/class/user.class.php', 'label' => 'UserModif', 'enabled' => 1, 'position' => 520, 'notnull' => 0, 'visible' => 0, 'foreignkey' => 'user.rowid', 'comment' => 'User who last modified'),
        'datec' => array('type' => 'datetime', 'label' => 'DateCreation', 'enabled' => 1, 'position' => 530, 'notnull' => 1, 'visible' => 0, 'comment' => 'Creation date'),
        'tms' => array('type' => 'timestamp', 'label' => 'DateModification', 'enabled' => 1, 'position' => 540, 'notnull' => 0, 'visible' => 0, 'comment' => 'Last modification date'),
        'entity' => array('type' => 'integer', 'label' => 'Entity', 'enabled' => 1, 'position' => 550, 'notnull' => 1, 'visible' => 0, 'default' => 1, 'index' => 1, 'comment' => 'Multi-company entity'),
    );

    /**
     * @var int Object ID
     */
    public $id;

    /**
     * @var string Reference
     */
    public $ref;

    /**
     * @var string Public client identifier
     */
    public $client_id;

    /**
     * @var string|null Hashed client secret (null for public clients)
     */
    public $client_secret;

    /**
     * @var string Display name
     */
    public $name;

    /**
     * @var string|null Description
     */
    public $description;

    /**
     * @var string|null Logo URL
     */
    public $logo_url;

    /**
     * @var string JSON array of redirect URIs
     */
    public $redirect_uris;

    /**
     * @var string JSON array of allowed scopes
     */
    public $allowed_scopes;

    /**
     * @var string JSON array of allowed grant types
     */
    public $allowed_grants;

    /**
     * @var int 1 if confidential client, 0 if public
     */
    public $is_confidential;

    /**
     * @var int 1 if PKCE required
     */
    public $require_pkce;

    /**
     * @var int Access token lifetime in seconds
     */
    public $access_token_lifetime;

    /**
     * @var int Refresh token lifetime in seconds
     */
    public $refresh_token_lifetime;

    /**
     * @var int|null Service user ID for client_credentials grant (M2M)
     */
    public $fk_service_user;

    /**
     * @var int Status (0=disabled, 1=enabled)
     */
    public $status;

    /**
     * @var int User who created
     */
    public $fk_user_author;

    /**
     * @var int User who last modified
     */
    public $fk_user_modif;

    /**
     * @var int|string Creation date
     */
    public $datec;

    /**
     * @var int|string Modification timestamp
     */
    public $tms;

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
        global $conf, $langs;

        $this->db = $db;

        if (!getDolGlobalInt('MAIN_SHOW_TECHNICAL_ID') && isset($this->fields['rowid'])) {
            $this->fields['rowid']['visible'] = 0;
        }
        if (!isModEnabled('multicompany') && isset($this->fields['entity'])) {
            $this->fields['entity']['enabled'] = 0;
        }

        // Unset fields that are disabled
        foreach ($this->fields as $key => $val) {
            if (isset($val['enabled']) && empty($val['enabled'])) {
                unset($this->fields[$key]);
            }
        }

        // Translate some data of arrayofkeyval
        if (is_object($langs)) {
            foreach ($this->fields as $key => $val) {
                if (!empty($val['arrayofkeyval']) && is_array($val['arrayofkeyval'])) {
                    foreach ($val['arrayofkeyval'] as $key2 => $val2) {
                        $this->fields[$key]['arrayofkeyval'][$key2] = $langs->trans($val2);
                    }
                }
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

        // Generate client_id if not set
        if (empty($this->client_id)) {
            $this->client_id = $this->generateClientId();
        }

        // Set defaults
        if (!isset($this->is_confidential)) {
            $this->is_confidential = 1;
        }
        if (!isset($this->require_pkce)) {
            $this->require_pkce = 0;
        }
        if (!isset($this->access_token_lifetime)) {
            $this->access_token_lifetime = 3600;
        }
        if (!isset($this->refresh_token_lifetime)) {
            $this->refresh_token_lifetime = 2592000;
        }
        if (!isset($this->status)) {
            $this->status = self::STATUS_ENABLED;
        }
        if (empty($this->datec)) {
            $this->datec = dol_now();
        }
        if (empty($this->entity)) {
            $this->entity = $conf->entity;
        }

        $this->fk_user_author = $user->id;

        // Ensure JSON arrays are properly formatted
        if (is_array($this->redirect_uris)) {
            $this->redirect_uris = json_encode($this->redirect_uris);
        }
        if (is_array($this->allowed_scopes)) {
            $this->allowed_scopes = json_encode($this->allowed_scopes);
        }
        if (is_array($this->allowed_grants)) {
            $this->allowed_grants = json_encode($this->allowed_grants);
        }

        return $this->createCommon($user, $notrigger);
    }

    /**
     * Load object in memory from the database
     *
     * @param int    $id       Id object
     * @param string $ref      Ref
     * @param string $client_id Client ID
     * @return int             <0 if KO, 0 if not found, >0 if OK
     */
    public function fetch($id, $ref = null, $client_id = '')
    {
        if (!empty($client_id)) {
            $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . $this->table_element;
            $sql .= " WHERE client_id = '" . $this->db->escape($client_id) . "'";
            $sql .= " AND entity IN (" . getEntity($this->element) . ")";

            $resql = $this->db->query($sql);
            if ($resql) {
                $obj = $this->db->fetch_object($resql);
                if ($obj) {
                    $id = $obj->rowid;
                }
                $this->db->free($resql);
            }
        }

        $result = $this->fetchCommon($id, $ref);
        return $result;
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
        dol_syslog("SmartAuth ".__METHOD__, LOG_DEBUG);

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
        $this->fk_user_modif = $user->id;

        // Ensure JSON arrays are properly formatted
        if (is_array($this->redirect_uris)) {
            $this->redirect_uris = json_encode($this->redirect_uris);
        }
        if (is_array($this->allowed_scopes)) {
            $this->allowed_scopes = json_encode($this->allowed_scopes);
        }
        if (is_array($this->allowed_grants)) {
            $this->allowed_grants = json_encode($this->allowed_grants);
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
     * Generate a unique client_id
     *
     * @return string Client ID (format: smartauth_XXXXXXXX)
     */
    public function generateClientId()
    {
        return 'smartauth_' . bin2hex(random_bytes(16));
    }

    /**
     * Generate a random client secret
     *
     * @return string Plain text secret (must be shown to user once, then hashed)
     */
    public function generateClientSecret()
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Set client secret (hashes it before storing)
     *
     * @param string $plainSecret Plain text secret
     * @return void
     */
    public function setClientSecret($plainSecret)
    {
        $this->client_secret = password_hash($plainSecret, PASSWORD_DEFAULT);
    }

    /**
     * Verify a client secret against the stored hash
     *
     * @param string $secret Plain text secret to verify
     * @return bool True if secret matches
     */
    public function verifySecret($secret)
    {
        if (empty($this->client_secret)) {
            // Public client, no secret required
            return true;
        }
        return password_verify($secret, $this->client_secret);
    }

    /**
     * Check if a redirect URI is allowed for this client
     *
     * @param string $uri URI to check
     * @return bool True if allowed
     */
    public function isRedirectUriAllowed($uri)
    {
        $allowedUris = $this->getRedirectUrisArray();
        return in_array($uri, $allowedUris, true);
    }

    /**
     * Check if a scope is allowed for this client
     *
     * @param string $scope Scope to check
     * @return bool True if allowed
     */
    public function isScopeAllowed($scope)
    {
        $allowedScopes = $this->getAllowedScopesArray();
        return in_array($scope, $allowedScopes, true);
    }

    /**
     * Check if multiple scopes are all allowed
     *
     * @param array $scopes Array of scopes to check
     * @return bool True if all scopes are allowed
     */
    public function areScopesAllowed(array $scopes)
    {
        foreach ($scopes as $scope) {
            if (!$this->isScopeAllowed($scope)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Check if a grant type is allowed for this client
     *
     * @param string $grant Grant type to check
     * @return bool True if allowed
     */
    public function isGrantAllowed($grant)
    {
        $allowedGrants = $this->getAllowedGrantsArray();
        return in_array($grant, $allowedGrants, true);
    }

    /**
     * Get redirect URIs as array
     *
     * @return array Array of redirect URIs
     */
    public function getRedirectUrisArray()
    {
        if (empty($this->redirect_uris)) {
            return array();
        }
        if (is_array($this->redirect_uris)) {
            return $this->redirect_uris;
        }
        $decoded = json_decode($this->redirect_uris, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Get allowed scopes as array
     *
     * @return array Array of allowed scopes
     */
    public function getAllowedScopesArray()
    {
        if (empty($this->allowed_scopes)) {
            return array();
        }
        if (is_array($this->allowed_scopes)) {
            return $this->allowed_scopes;
        }
        $decoded = json_decode($this->allowed_scopes, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Get allowed grants as array
     *
     * @return array Array of allowed grant types
     */
    public function getAllowedGrantsArray()
    {
        if (empty($this->allowed_grants)) {
            return array();
        }
        if (is_array($this->allowed_grants)) {
            return $this->allowed_grants;
        }
        $decoded = json_decode($this->allowed_grants, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Set redirect URIs from array
     *
     * @param array $uris Array of redirect URIs
     * @return void
     */
    public function setRedirectUrisArray(array $uris)
    {
        $this->redirect_uris = json_encode(array_values($uris));
    }

    /**
     * Set allowed scopes from array
     *
     * @param array $scopes Array of scopes
     * @return void
     */
    public function setAllowedScopesArray(array $scopes)
    {
        $this->allowed_scopes = json_encode(array_values($scopes));
    }

    /**
     * Set allowed grants from array
     *
     * @param array $grants Array of grant types
     * @return void
     */
    public function setAllowedGrantsArray(array $grants)
    {
        $this->allowed_grants = json_encode(array_values($grants));
    }

    /**
     * Check if client is enabled
     *
     * @return bool True if enabled
     */
    public function isEnabled()
    {
        return $this->status == self::STATUS_ENABLED;
    }

    /**
     * Check if client is confidential (has secret)
     *
     * @return bool True if confidential
     */
    public function isConfidential()
    {
        return !empty($this->is_confidential);
    }

    /**
     * Check if client requires PKCE
     *
     * @return bool True if PKCE required
     */
    public function requiresPkce()
    {
        // Public clients always require PKCE
        if (!$this->isConfidential()) {
            return true;
        }
        return !empty($this->require_pkce);
    }

    /**
     * Return the label of the status
     *
     * @param int $mode 0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
     * @return string Label of status
     */
    public function getLibStatut($mode = 0)
    {
        return $this->LibStatut($this->status, $mode);
    }

    // phpcs:disable PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps
    /**
     * Return the label of a given status
     *
     * @param int $status Id status
     * @param int $mode   0=long label, 1=short label, 2=Picto + short label, 3=Picto, 4=Picto + long label, 5=Short label + Picto, 6=Long label + Picto
     * @return string     Label of status
     */
    public function LibStatut($status, $mode = 0)
    {
        // phpcs:enable
        global $langs;

        $labelStatus = array(
            self::STATUS_DISABLED => $langs->transnoentitiesnoconv('Disabled'),
            self::STATUS_ENABLED => $langs->transnoentitiesnoconv('Enabled'),
        );
        $labelStatusShort = array(
            self::STATUS_DISABLED => $langs->transnoentitiesnoconv('Disabled'),
            self::STATUS_ENABLED => $langs->transnoentitiesnoconv('Enabled'),
        );

        $statusType = 'status' . $status;
        if ($status == self::STATUS_ENABLED) {
            $statusType = 'status4';
        }
        if ($status == self::STATUS_DISABLED) {
            $statusType = 'status5';
        }

        return dolGetStatus($labelStatus[$status], $labelStatusShort[$status], '', $statusType, $mode);
    }

    /**
     * Get post logout redirect URIs as array
     *
     * @return array Array of post logout redirect URIs
     */
    public function getPostLogoutRedirectUrisArray()
    {
        // Check if the property exists (field may not be in older schema)
        if (!property_exists($this, 'post_logout_redirect_uris') || empty($this->post_logout_redirect_uris)) {
            return array();
        }
        if (is_array($this->post_logout_redirect_uris)) {
            return $this->post_logout_redirect_uris;
        }
        $decoded = json_decode($this->post_logout_redirect_uris, true);
        return is_array($decoded) ? $decoded : array();
    }

    /**
     * Initialise object with example values
     *
     * @return void
     */
    public function initAsSpecimen()
    {
        $this->initAsSpecimenCommon();
        $this->ref = 'OAUTH-SPECIMEN';
        $this->client_id = $this->generateClientId();
        $this->name = 'Example OAuth Client';
        $this->description = 'This is an example OAuth client';
        $this->redirect_uris = json_encode(array('https://example.com/callback'));
        $this->allowed_scopes = json_encode(array('openid', 'profile', 'email'));
        $this->allowed_grants = json_encode(array('authorization_code', 'refresh_token'));
        $this->is_confidential = 1;
        $this->require_pkce = 0;
        $this->status = self::STATUS_ENABLED;
    }
}
