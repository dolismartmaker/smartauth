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


-- DROP TABLE llx_smartauth_logs;

CREATE TABLE llx_smartauth_logs(
	-- BEGIN MODULEBUILDER FIELDS
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	appuid integer, --from module->numero
	fk_key integer, --link to smartauth_auth rowid
	entity INTEGER,
	dol_element varchar(32),
	ip varchar(20),
	method varchar(8),
    http_status smallint(6),
    bytes_sent int(11),
    content_type varchar(20),
    url_requested varchar(255),
    user_agent varchar(100),
    referer varchar(255),
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
	-- END MODULEBUILDER FIELDS
) ENGINE=innodb;
