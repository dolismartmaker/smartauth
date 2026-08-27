<?php

/**
 * ApiAudience.php
 *
 * Single point of reading and writing of SMARTAUTH_API_AUDIENCE, the allow-list
 * of OAuth clients entitled to call the 'oauth2'-protected routes of the
 * instance (first-party API).
 *
 * The gate is closed by default (audit S-4): with the constant unset, no client
 * gets through, because any OAuth2 client of the instance would otherwise be
 * able to call any route of any consumer module with the rights of its service
 * user. The price of that safety is a constant an operator has to fill in by
 * hand, on a screen that never mentions it, with a 401 as the only symptom --
 * hence this class and the buttons on the client card that use it.
 *
 * The list is read on the entity serving the request. A client living in
 * another entity is therefore granted where the API is called, and not where
 * the client sheet was created: on a mono-entity instance the two are the same,
 * and on a multi-entity one the reading is what decides.
 *
 * Copyright (c) 2026 Eric Seigne <eric.seigne@cap-rel.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

namespace SmartAuth\Api\OAuth2;

class ApiAudience
{
    /**
     * Name of the Dolibarr constant holding the allow-list
     */
    const CONSTANT = 'SMARTAUTH_API_AUDIENCE';

    /**
     * The client_id(s) allowed on the first-party API
     *
     * @return string[] Client identifiers, in the order they were written
     */
    public static function listed(): array
    {
        return self::parse(getDolGlobalString(self::CONSTANT, ''));
    }

    /**
     * Whether a client is allowed on the first-party API
     *
     * @param string $clientId Client identifier to look for
     * @return bool True when the client is in the allow-list
     */
    public static function allows(string $clientId): bool
    {
        return in_array(trim($clientId), self::listed(), true);
    }

    /**
     * The single audience to hand to the JWT validator, if there is one
     *
     * A single configured client is checked against the 'aud' claim of the
     * token (RFC 8725). With several of them the claim cannot single one out,
     * so membership is the only check left and this returns null.
     *
     * @return string|null The lone client_id, or null
     */
    public static function expected(): ?string
    {
        $listed = self::listed();

        return count($listed) === 1 ? $listed[0] : null;
    }

    /**
     * Add a client to the allow-list, preserving the entries already there
     *
     * @param \DoliDB $db Database handler
     * @param string $clientId Client identifier to allow
     * @param int $entity Entity the constant is written on
     * @return bool True on success, false when the constant could not be written
     */
    public static function grant($db, string $clientId, int $entity): bool
    {
        $clientId = trim($clientId);

        if ($clientId === '') {
            dol_syslog('[SmartAuth] ApiAudience::grant refused an empty client id', LOG_WARNING);

            return false;
        }

        $listed = self::listed();

        if (in_array($clientId, $listed, true)) {
            // Already there: nothing to write, and saying so beats a second
            // identical entry in the constant.
            return true;
        }

        $listed[] = $clientId;

        return self::write($db, $listed, $entity);
    }

    /**
     * Remove a client from the allow-list, leaving the other entries alone
     *
     * The other products of the house are named in that same constant, and
     * rewriting it wholesale on a revocation would cut them all off.
     *
     * @param \DoliDB $db Database handler
     * @param string $clientId Client identifier to remove
     * @param int $entity Entity the constant is written on
     * @return bool True on success, false when the constant could not be written
     */
    public static function revoke($db, string $clientId, int $entity): bool
    {
        $clientId = trim($clientId);
        $listed = self::listed();
        $kept = array_values(array_filter($listed, function ($listedId) use ($clientId) {
            return $listedId !== $clientId;
        }));

        if (count($kept) === count($listed)) {
            dol_syslog('[SmartAuth] ApiAudience::revoke: ' . $clientId . ' was not in the allow-list', LOG_NOTICE);

            return true;
        }

        return self::write($db, $kept, $entity);
    }

    /**
     * Split the raw constant into client identifiers
     *
     * Blanks and duplicates are dropped: the constant is typed by a human, and
     * a trailing comma or a doubled entry must not turn into a client nobody
     * can find in the list.
     *
     * @param string $raw Raw value of the constant
     * @return string[] Client identifiers
     */
    public static function parse(string $raw): array
    {
        $ids = array_map('trim', explode(',', $raw));

        return array_values(array_unique(array_filter($ids, function ($id) {
            return $id !== '';
        })));
    }

    /**
     * Write the allow-list back into the constant
     *
     * An empty list writes an empty value rather than deleting the constant:
     * both close the gate, and the row left behind is what shows an operator
     * the setting exists at all.
     *
     * @param \DoliDB|null $db Database handler; null writes the in-memory
     *                         $conf->global value only (unit harness / CLI
     *                         probe - no persistence layer to talk to)
     * @param string[] $ids Client identifiers to store
     * @param int $entity Entity the constant is written on
     * @return bool True on success
     */
    private static function write($db, array $ids, int $entity): bool
    {
        $value = implode(',', $ids);

        if ($db === null) {
            // Same semantics as the unit bootstrap's dolibarr_set_const
            // stub: the read side (listed()) works off $conf->global, so a
            // memory-only write keeps grant/revoke coherent without a
            // database handle.
            global $conf;
            if (!isset($conf->global) || !is_object($conf->global)) {
                dol_syslog('[SmartAuth] ApiAudience::write has neither a db handler nor a shaped $conf->global', LOG_ERR);
                return false;
            }
            $conf->global->{self::CONSTANT} = $value;
            return true;
        }

        $result = dolibarr_set_const($db, self::CONSTANT, $value, 'chaine', 0, '', $entity);

        if ($result <= 0) {
            dol_syslog('[SmartAuth] ApiAudience::write could not save ' . self::CONSTANT, LOG_ERR);

            return false;
        }

        dol_syslog('[SmartAuth] ApiAudience::write saved ' . count($ids) . ' allowed client(s) on entity ' . $entity, LOG_NOTICE);

        return true;
    }
}
