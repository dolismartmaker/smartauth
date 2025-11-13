-- add geodata to ecm files
ALTER TABLE llx_ecm_files ADD COLUMN geolat double(24,8) DEFAULT NULL;
ALTER TABLE llx_ecm_files ADD COLUMN geolong double(24,8) DEFAULT NULL;
ALTER TABLE llx_ecm_files ADD COLUMN geopoint point DEFAULT NULL;
ALTER TABLE llx_ecm_files ADD COLUMN georesultcode varchar(16) NULL;
