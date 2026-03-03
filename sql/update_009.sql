-- Add service user for client_credentials grant (M2M authentication)
ALTER TABLE llx_smartauth_oauth_clients ADD COLUMN fk_service_user INTEGER NULL AFTER refresh_token_lifetime;
ALTER TABLE llx_smartauth_oauth_clients ADD INDEX idx_oauth_clients_service_user (fk_service_user);
