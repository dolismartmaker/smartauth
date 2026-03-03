<?php

/**
 * OAuth2 client fixtures for testing
 *
 * Returns an array of test client configurations.
 * Use with OAuthTestCase::createTestClientFromFixture()
 */

return [
    // Confidential client with secret (typical web application)
    'confidential' => [
        'ref' => 'TEST-CONF-001',
        'client_id' => 'test-confidential-client',
        'client_secret' => 'test-secret-confidential-12345',
        'name' => 'Test Confidential Client',
        'description' => 'A confidential OAuth client for testing',
        'redirect_uris' => ['https://app.example.com/callback', 'https://app.example.com/auth/callback'],
        'allowed_scopes' => ['openid', 'profile', 'email', 'groups', 'roles', 'offline_access'],
        'allowed_grants' => ['authorization_code', 'refresh_token'],
        'is_confidential' => 1,
        'require_pkce' => 0,
        'access_token_lifetime' => 3600,
        'refresh_token_lifetime' => 2592000,
        'status' => 1,
    ],

    // Public client (SPA or mobile app, requires PKCE)
    'public' => [
        'ref' => 'TEST-PUB-001',
        'client_id' => 'test-public-client',
        'client_secret' => null,
        'name' => 'Test Public Client',
        'description' => 'A public OAuth client (SPA) for testing',
        'redirect_uris' => ['http://localhost:3000/callback', 'https://spa.example.com/callback'],
        'allowed_scopes' => ['openid', 'profile', 'email', 'offline_access'],
        'allowed_grants' => ['authorization_code', 'refresh_token'],
        'is_confidential' => 0,
        'require_pkce' => 1,
        'access_token_lifetime' => 1800,
        'refresh_token_lifetime' => 604800,
        'status' => 1,
    ],

    // Confidential client with PKCE required
    'confidential_pkce' => [
        'ref' => 'TEST-CONF-PKCE-001',
        'client_id' => 'test-confidential-pkce-client',
        'client_secret' => 'test-secret-pkce-12345',
        'name' => 'Test Confidential PKCE Client',
        'description' => 'A confidential client with mandatory PKCE',
        'redirect_uris' => ['https://secure-app.example.com/callback'],
        'allowed_scopes' => ['openid', 'profile', 'email'],
        'allowed_grants' => ['authorization_code', 'refresh_token'],
        'is_confidential' => 1,
        'require_pkce' => 1,
        'access_token_lifetime' => 3600,
        'refresh_token_lifetime' => 2592000,
        'status' => 1,
    ],

    // Disabled client
    'disabled' => [
        'ref' => 'TEST-DIS-001',
        'client_id' => 'test-disabled-client',
        'client_secret' => 'test-secret-disabled-12345',
        'name' => 'Test Disabled Client',
        'description' => 'A disabled OAuth client',
        'redirect_uris' => ['https://disabled-app.example.com/callback'],
        'allowed_scopes' => ['openid', 'profile'],
        'allowed_grants' => ['authorization_code'],
        'is_confidential' => 1,
        'require_pkce' => 0,
        'access_token_lifetime' => 3600,
        'refresh_token_lifetime' => 2592000,
        'status' => 0, // Disabled
    ],

    // Limited scopes client
    'limited_scopes' => [
        'ref' => 'TEST-LIM-001',
        'client_id' => 'test-limited-client',
        'client_secret' => 'test-secret-limited-12345',
        'name' => 'Test Limited Scopes Client',
        'description' => 'A client with limited scopes (no offline_access)',
        'redirect_uris' => ['https://limited-app.example.com/callback'],
        'allowed_scopes' => ['openid', 'profile'], // No email, no offline_access
        'allowed_grants' => ['authorization_code'], // No refresh_token
        'is_confidential' => 1,
        'require_pkce' => 0,
        'access_token_lifetime' => 1800,
        'refresh_token_lifetime' => 0,
        'status' => 1,
    ],

    // Dolibarr internal client (mimics real deployment)
    'dolibarr' => [
        'ref' => 'DOLIBARR-INTERNAL',
        'client_id' => 'dolibarr-erp',
        'client_secret' => null, // Public client with PKCE
        'name' => 'Dolibarr ERP',
        'description' => 'Client OAuth interne pour authentification Dolibarr',
        'redirect_uris' => ['https://erp.example.com/index.php'],
        'allowed_scopes' => ['openid', 'profile', 'email', 'groups'],
        'allowed_grants' => ['authorization_code', 'refresh_token'],
        'is_confidential' => 0,
        'require_pkce' => 1,
        'access_token_lifetime' => 3600,
        'refresh_token_lifetime' => 2592000,
        'status' => 1,
    ],

    // Confidential client for client_credentials grant (M2M)
    'client_credentials' => [
        'ref' => 'TEST-CC-001',
        'client_id' => 'test-m2m-client',
        'client_secret' => 'test-secret-m2m-12345',
        'name' => 'Test M2M Client',
        'description' => 'A confidential OAuth client for machine-to-machine testing',
        'redirect_uris' => ['https://m2m.example.com/callback'],
        'allowed_scopes' => ['openid', 'profile', 'email'],
        'allowed_grants' => ['client_credentials'],
        'is_confidential' => 1,
        'require_pkce' => 0,
        'access_token_lifetime' => 3600,
        'refresh_token_lifetime' => 0,
        'status' => 1,
        'fk_service_user' => null, // Will be set dynamically in tests
    ],

    // Confidential client for client_credentials without service user
    'client_credentials_no_user' => [
        'ref' => 'TEST-CC-NOUSER-001',
        'client_id' => 'test-m2m-nouser-client',
        'client_secret' => 'test-secret-m2m-nouser-12345',
        'name' => 'Test M2M Client (No Service User)',
        'description' => 'A M2M client without fk_service_user configured',
        'redirect_uris' => ['https://m2m-nouser.example.com/callback'],
        'allowed_scopes' => ['openid', 'profile'],
        'allowed_grants' => ['client_credentials'],
        'is_confidential' => 1,
        'require_pkce' => 0,
        'access_token_lifetime' => 1800,
        'refresh_token_lifetime' => 0,
        'status' => 1,
    ],

    // Nextcloud integration client
    'nextcloud' => [
        'ref' => 'NEXTCLOUD-001',
        'client_id' => 'nextcloud-app',
        'client_secret' => 'nextcloud-secret-12345',
        'name' => 'Nextcloud',
        'description' => 'Nextcloud OIDC integration',
        'redirect_uris' => ['https://cloud.example.com/apps/user_oidc/code'],
        'allowed_scopes' => ['openid', 'profile', 'email', 'groups', 'roles'],
        'allowed_grants' => ['authorization_code', 'refresh_token'],
        'is_confidential' => 1,
        'require_pkce' => 0,
        'access_token_lifetime' => 3600,
        'refresh_token_lifetime' => 2592000,
        'status' => 1,
    ],
];
