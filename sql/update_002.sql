ALTER TABLE `llx_smartauth_auth` ADD `fk_authid` INTEGER NOT NULL AFTER `fk_user_modif`;
ALTER TABLE `llx_smartauth_auth` ADD `auth_element` VARCHAR(128) NOT NULL AFTER `fk_authid`;
