# SMARTAUTH FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

[![pipeline status](https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/badges/master/pipeline.svg)](https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/-/commits/master)
[![coverage report](https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/badges/master/coverage.svg)](https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/-/commits/master)

## Overview

SmartAuth is a modern authentication module for Dolibarr that provides JWT-based API authentication with advanced security features. It enables per-device token management, allowing users to maintain multiple authenticated sessions across different devices.

**Key principle**: Each device gets its own token pair (access + refresh). If a device is lost or compromised, revoke only that device's tokens without affecting other sessions.

## Features

### Authentication
- **JWT Token Authentication**: Secure token-based API access using industry-standard JSON Web Tokens
- **Token Families**: Each login creates a token family, grouping access and refresh tokens for cascade revocation
- **Dual Token System**: Short-lived access tokens (configurable TTL) + long-lived refresh tokens
- **Device Management**: Link API tokens to specific devices via UUID, enabling per-device session control

### Security
- **Token Replay Detection**: JTI (JWT ID) tracking prevents token reuse after refresh
- **Cascade Revocation**: Revoking a refresh token automatically invalidates all related access tokens
- **Progressive Rate Limiting**: Exponential backoff on failed login attempts (30s -> 5min -> 1h)
- **Input Sanitization**: All API inputs are validated and sanitized before processing
- **SQL Injection Protection**: Parameterized queries throughout the codebase

### API Features
- **RESTful Endpoints**: Login, logout, refresh, device management, password reset
- **Multi-entity Support**: Compatible with Dolibarr's multi-company mode
- **Route Caching**: Optimized routing with compiled PHP cache files
- **Automatic CORS**: Built-in CORS handling with configurable origins
- **Extensible Hooks**: SmartMaker hooks for validation schemas and custom sanitizers
- **Offline Sync**: Client registration and data synchronization for offline-capable apps

### Monitoring
- **Comprehensive Logging**: All authentication events are logged with sanitized data
- **Refresh Token Monitoring**: Track token usage patterns and detect anomalies
- **Rate Limit Tracking**: Monitor and analyze failed authentication attempts

## Installation

1. Download the module from [releases](https://cloud.cap-rel.fr/index.php/s/G6AKiSKCwPR9HLx)
2. Extract to `htdocs/custom/smartauth/`
3. Enable the module in Dolibarr: Setup > Modules > SmartAuth
4. Configure token TTL and security settings in module configuration

## API Endpoints

### Authentication

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/login` | Authenticate user, returns access + refresh tokens |
| GET | `/refresh` | Get new access token using refresh token |
| POST | `/logout` | Revoke current token family |
| POST | `/password-reset` | Request password reset email |

### Device Management

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/device` | Register/update device information |
| GET | `/login` | List available entities for user |

### Offline Sync

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/sync/register` | Register a sync client |
| GET | `/sync/pull` | Pull changes from server |
| POST | `/sync/push` | Push changes to server |
| GET | `/sync/status` | Get sync status |
| GET | `/sync/conflicts` | List pending conflicts |
| POST | `/sync/conflicts/{id}/resolve` | Resolve a specific conflict |

### Request Headers

```
Authorization: Bearer <access_token>
X-DeviceId: <device-uuid>
Content-Type: application/json
```

## Architecture

### Token Structure

```
Access Token (short-lived):
{
  "iat": 1234567890,
  "exp": 1234571490,
  "jti": "unique-token-id",
  "fk_user": 1,
  "fk_soc": null,
  "entity": 1,
  "family": "token-family-uuid"
}

Refresh Token (long-lived):
{
  "iat": 1234567890,
  "exp": 1234654290,
  "jti": "unique-refresh-id",
  "type": "refresh",
  "family": "token-family-uuid"
}
```

### Database Tables

- `llx_smartauth_auth`: Token records, families, and authentication data
- `llx_smartauth_devices`: Registered devices with UUID and labels
- `llx_smartauth_logs`: Authentication event logs
- `llx_smartauth_ratelimit`: Rate limiting tracking
- `llx_smartauth_sync_clients`: Registered sync clients for offline mode
- `llx_smartauth_sync_events`: Sync events and change tracking

## Integration with Other Modules

SmartAuth provides hooks for other Dolibarr modules to extend validation and sanitization.

### SmartMaker Hooks

External modules can register validation schemas via `smartmaker_addValidationSchemas` and custom sanitizers via `smartmaker_addSanitizers`.

See [documentation/hooks.md](documentation/hooks.md) for complete hook documentation.

### Example Integration

```php
// In your module: class/actions_mymodule.class.php
class ActionsMyModule
{
    public function smartmaker_addValidationSchemas($parameters, &$schemas, &$action, $hookmanager)
    {
        $schemas['mymodule'] = [
            'POST:/myendpoint' => [
                'field1' => ['type' => InputSanitizer::TYPE_STRING, 'required' => true],
                'field2' => ['type' => InputSanitizer::TYPE_INT, 'min' => 0],
            ],
        ];
        return 0;
    }
}
```

## Configuration

### Module Configuration

Configure via Dolibarr Setup > Modules > SmartAuth:

- **SMARTAUTH_TOKEN_TTL**: Access token lifetime in seconds (default: 3600)
- **SMARTAUTH_REFRESH_TTL**: Refresh token lifetime in seconds (default: 86400)
- **SMARTAUTH_RATE_LIMIT_ENABLED**: Enable/disable rate limiting
- **SMARTAUTH_CORS_ORIGIN**: Allowed CORS origin (default: '*')
- **SMARTAUTH_CORS_METHODS**: Allowed HTTP methods (default: 'GET, POST, PUT, DELETE, PATCH, OPTIONS')
- **SMARTAUTH_CORS_HEADERS**: Allowed request headers (default: 'Content-Type, Authorization, X-DeviceId')

### App Integration

When using SmartAuth with smartboot, JWT keys are **fully automatic**. Just initialize the route cache:

```php
RouteCache::init('MYMODULE');
```

**That's it.** No configuration needed. SmartAuth handles JWT keys internally:
- Generated (64-character secure random hex string) on first API request
- Stored in `llx_const` as `MYMODULE_JWT_KEY`
- Retrieved from database on subsequent requests

The `JwtKeyHelper` class is available for advanced use cases:
- `hasValidKey($moduleName)`: Check if a valid key exists
- `rotateKey($moduleName)`: Force key regeneration (invalidates all tokens)

### CORS

CORS is handled automatically by SmartAuth. Preflight OPTIONS requests are answered with a 200 response. Configure allowed origins via `SMARTAUTH_CORS_ORIGIN` if you need to restrict access to specific domains.

## Security Best Practices

1. **Token Storage**: Store refresh tokens securely on client devices
2. **HTTPS**: Always use HTTPS in production
3. **Token Refresh**: Refresh access tokens before expiration
4. **Device Registration**: Use unique device UUIDs (RFC 4122 v4 format)
5. **Logout on Compromise**: Immediately revoke tokens if a device is compromised

## Testing

The module includes comprehensive test suites:

```bash
# Unit tests
./vendor/bin/phpunit -c phpunit.xml

# Integration tests with Dolibarr
./vendor/bin/phpunit -c phpunit-dolibarr-integration.xml

# Integration tests with SQLite (fast)
./vendor/bin/phpunit -c phpunit-dolibarr-integration-sqlite.xml
```

### Test Coverage

- Unit tests: Input sanitization, validation schemas, rate limiting
- Integration tests: Full auth flows, device management, token refresh
- Security tests: Token replay, cascade revocation, SQL injection protection
- Concurrency tests: Race conditions, simultaneous operations

## Licenses

### Main code

GPLv3 or (at your option) any later version. See file COPYING for more information.

### Documentation

All texts and readmes are licensed under GFDL.

French documentation: https://doc.cap-rel.fr/projet_smartauth/accueil

## Contributing

1. Fork the repository
2. Create a feature branch
3. Write tests for new functionality
4. Ensure all tests pass
5. Submit a pull request

## Support

- Issue tracker: https://inligit.fr/cap-rel/dolibarr/plugin-smartauth/-/issues
- Documentation: https://doc.cap-rel.fr/projet_smartauth/accueil
