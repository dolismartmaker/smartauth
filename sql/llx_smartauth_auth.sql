-- Copyright (C) 2023 Eric Seigne <eric.seigne@cap-rel.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.

CREATE TABLE llx_smartauth_auth(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	appuid integer, --from module->numero
    salt varchar(32),
	date_creation datetime NOT NULL,
	date_lastused datetime,
	refresh_count INTEGER DEFAULT 0,
	date_eol datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat integer NOT NULL, -- id of created user
	fk_user_modif integer,
	fk_authid integer NOT NULL, -- id of authenticated user or element
	parent_token_id INTEGER DEFAULT NULL,
	auth_element varchar(128) NOT NULL, -- may be user or societe_account or ...
	token_type VARCHAR(20) DEFAULT 'access',
	ip varchar(50) DEFAULT '',
	fk_device_id integer NOT NULL, -- note: a key is linked to a device
	status integer NOT NULL,
	entity integer DEFAULT 1 NOT NULL
) ENGINE=innodb;
