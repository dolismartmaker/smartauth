# SmartAuth Security Documentation

This document describes the security mechanisms implemented in the SmartAuth module and the configuration requirements for secure deployment.

## Table of Contents

1. [Configuration Requirements](#configuration-requirements)
2. [JWT Security](#jwt-security)
3. [Token Replay Prevention](#token-replay-prevention)
4. [Device UUID Validation](#device-uuid-validation)
5. [Rate Limiting](#rate-limiting)
6. [Input Sanitization](#input-sanitization)
7. [Logging and Audit](#logging-and-audit)
8. [Deployment Checklist](#deployment-checklist)

---

## Configuration Requirements

### smartAuthAppKey (CRITICAL)

The `$smartAuthAppKey` global variable is a **mandatory** secret key used in JWT signature generation. This key must be:

- **Defined** before any SmartAuth API call
- **At least 32 characters** long
- **Cryptographically random** (use `bin2hex(random_bytes(32))` to generate)
- **Kept secret** (never commit to version control, use environment variables)

#### How it works

The JWT signing key is constructed as:
```
key = salt (32 chars, per-token, stored in DB)
    + salt2 (16 chars, derived from device UUID)
    + smartAuthAppKey (your secret)
```

This design ensures that even if an attacker gains read access to the database (obtaining `salt`), they cannot forge tokens without knowing `smartAuthAppKey`.

#### Configuration example

```php
// In your application bootstrap (e.g., master.inc.php)
global $smartAuthAppKey;
$smartAuthAppKey = getenv('SMARTAUTH_SECRET_KEY');

// Or from Dolibarr configuration
$smartAuthAppKey = getDolGlobalString('SMARTAUTH_SECRET_KEY');
```

#### What happens if misconfigured

If `$smartAuthAppKey` is empty or less than 32 characters, the module will throw an exception:
```
SmartAuth configuration error: smartAuthAppKey must be defined and at least 32 characters
```

This prevents the application from running with a weak or missing secret.

---

## JWT Security

### Token Structure

SmartAuth uses the `token_id|jwt` format where:
- `token_id`: Database row ID for quick lookup and revocation
- `jwt`: Standard JWT (HS256 algorithm) containing the payload

### Token Types

| Type | Lifetime | Purpose |
|------|----------|---------|
| Access Token | 15 minutes (default) | API authentication |
| Refresh Token | 7 days (default) | Obtain new access tokens |

### Token Payload

```json
{
  "jti": "unique-32-char-hex-id",
  "login": "user@example.com",
  "user_id": 123,
  "entity": 1,
  "token_type": "access|refresh",
  "family_id": 456,
  "device_id": 789,
  "refresh_count": 0,
  "exp": 1234567890
}
```

### Token Family

All tokens from a single login session belong to the same "family". If suspicious activity is detected (e.g., token replay), the entire family is revoked, forcing the user to re-authenticate on all devices.

---

## Token Replay Prevention

### The Problem

Without replay protection, an attacker who intercepts a refresh token could use it simultaneously with the legitimate user, obtaining their own valid token pair.

### Solution: JWT ID (jti)

Each token contains a unique `jti` (JWT ID) - a 32-character random hex string. When a refresh token is used:

1. The `jti` is extracted from the token (without signature verification)
2. An atomic INSERT is attempted into `llx_smartauth_jti_used` table
3. If INSERT succeeds: first use, continue with token validation
4. If INSERT fails (duplicate key): replay attack detected, revoke entire token family

### Why this works

The INSERT operation is **atomic** at the database level. Even if two identical requests arrive simultaneously, only one will succeed - the other will fail due to the PRIMARY KEY constraint.

### Database Schema

```sql
CREATE TABLE llx_smartauth_jti_used (
    jti VARCHAR(32) NOT NULL PRIMARY KEY,
    used_at INTEGER NOT NULL,
    token_id INTEGER DEFAULT NULL,
    INDEX idx_used_at (used_at)
);
```

### Cleanup

Old `jti` entries are automatically cleaned up (entries older than 30 days) with a 1% probability on each refresh request to avoid overhead.

### Backward Compatibility

Tokens created before the `jti` implementation (legacy tokens) will have `jti = null`. These tokens continue to work but do not benefit from replay protection. Users should re-authenticate to obtain new tokens with `jti`.

---

## Device UUID Validation

### Requirements

Every API request must include a valid device identifier in the `X-DeviceId` header. Accepted formats:

| Format | Pattern | Example |
|--------|---------|---------|
| UUID v4 (RFC 4122) | `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx` | `550e8400-e29b-41d4-a716-446655440000` |
| SHA256 hash | 64 hex characters | `a1b2c3d4...` (64 chars) |
| Hex identifier | 32-64 hex characters | `a1b2c3d4...` (32-64 chars) |

### Rejected Values

The following values are explicitly rejected to prevent client-side bugs from creating invalid devices:

- `undefined` (JavaScript undefined converted to string)
- `null` (JavaScript null converted to string)
- `NaN` (JavaScript NaN converted to string)
- `false`, `true` (JavaScript booleans converted to string)
- `0` (falsy value)
- Empty string

### Validation Flow

```
1. Raw value from X-DeviceId header
2. Check against blacklist (undefined, null, etc.)
3. Validate format via InputSanitizer::sanitizeUUID()
4. If invalid → Exception thrown, request rejected
5. If valid → Continue with authentication
```

### Why Device Tracking Matters

- **Security**: Each device has its own token salt (`salt2`), making tokens device-specific
- **Audit**: Track which devices access the account
- **Revocation**: Ability to revoke access for specific devices

---

## Rate Limiting

### Two-Layer Protection

| Layer | Identifier | Limit | Window | Purpose |
|-------|------------|-------|--------|---------|
| IP-based | Client IP | 10 attempts | 5 minutes | Prevent distributed attacks |
| Username-based | Login/email | 5 attempts | 15 minutes | Prevent targeted brute force |

### How it works

1. Before authentication, both limits are checked
2. If either limit is exceeded, request is rejected with HTTP 429
3. Failed attempts are recorded after each check
4. Successful login does NOT reset the counters (to prevent timing attacks)

### Configuration

Rate limiting parameters are defined in `RateLimiter.php` and can be adjusted:

```php
$rateLimiter->checkLimit($identifier, $action, $max_attempts, $window_seconds);
```

---

## Input Sanitization

### InputSanitizer Class

All user input is sanitized using the `InputSanitizer` class which provides:

| Method | Purpose |
|--------|---------|
| `sanitizeString()` | XSS protection via `htmlspecialchars()` |
| `sanitizeEmail()` | Email validation and normalization |
| `sanitizeUUID()` | UUID/hash format validation |
| `sanitizeIP()` | IP address validation |
| `sanitizeInt()` | Integer casting with bounds |
| `sanitizeBool()` | Boolean normalization |

### SQL Injection Protection

All SQL queries use `$db->escape()` for parameter escaping. Example:

```php
$sql .= " WHERE rowid = '" . $db->escape($family_id) . "'";
```

### LogSanitizer Class

Sensitive data is masked before logging:

- IP addresses: `192.168.1.100` → `192.168.xxx.xxx`
- Emails: `user@example.com` → `us**@example.com`
- Tokens: `abc123...` → `abc***`
- Salts: `abcdef12...` → `abcd****`

---

## Logging and Audit

### What is logged

- All authentication attempts (success/failure)
- Token refresh operations
- Device creation/association
- Rate limiting events
- Security violations (replay attacks, invalid tokens)

### Log Levels

| Level | Usage |
|-------|-------|
| `LOG_DEBUG` | Detailed debugging (disable in production) |
| `LOG_INFO` | Normal operations |
| `LOG_WARNING` | Suspicious activity, rate limiting |
| `LOG_ERR` | Errors, security violations |

### Sensitive Data

All sensitive data is sanitized before logging using `LogSanitizer` to prevent credential leakage in log files.

---

## Deployment Checklist

### Before Going Live

- [ ] **smartAuthAppKey** is defined and at least 32 characters
- [ ] **smartAuthAppKey** is stored securely (environment variable, not in code)
- [ ] **HTTPS** is enforced (HTTP redirects to HTTPS)
- [ ] **Debug logging** is disabled (`LOG_DEBUG` statements removed or level raised)
- [ ] **Database** has the `llx_smartauth_jti_used` table (run `update_009.sql`)

### Server Configuration

Add these HTTP headers at the web server level (Apache/Nginx):

```
Strict-Transport-Security: max-age=31536000; includeSubDomains
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
```

### Monitoring

- Monitor `LOG_WARNING` and `LOG_ERR` for security events
- Set up alerts for repeated "REPLAY ATTACK DETECTED" messages
- Review rate limiting logs for brute force patterns

### Regular Maintenance

- Rotate `smartAuthAppKey` periodically (requires all users to re-authenticate)
- Review and purge old entries in `llx_smartauth_jti_used` (automatic cleanup handles this)
- Audit device list per user for suspicious entries

---

## Security Contact

For security vulnerabilities, please contact the maintainers privately before public disclosure.
