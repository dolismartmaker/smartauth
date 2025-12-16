# SmartAuth API

SmartAuth is an authentication module for Dolibarr ERP/CRM that provides JWT-based authentication with refresh token support.

## Authentication

Most API endpoints require authentication via JWT Bearer token.

### How to authenticate

1. Call `POST /login` with your credentials to obtain an access token and refresh token
2. Include the access token in subsequent requests via the `Authorization` header:
   ```
   Authorization: Bearer <token_id>|<jwt>
   ```
3. When the access token expires, use `GET /refresh` with your refresh token to obtain new tokens

### Token Types

- **Access Token**: Short-lived token for API requests
- **Refresh Token**: Long-lived token used to obtain new access tokens

### Required Headers

| Header | Description | Required |
|--------|-------------|----------|
| `Authorization` | Bearer token for authenticated routes | Yes (protected routes) |
| `X-DeviceId` | Unique device identifier (UUID or SHA256 hash) | Yes |
| `Content-Type` | Must be `application/json` for POST/PUT requests | Yes |

## Rate Limiting

The API implements rate limiting to protect against brute force attacks:

- **IP-based**: 10 attempts per 5 minutes (configurable)
- **Username-based**: 5 attempts per 15 minutes (configurable)

When rate limited, the API returns HTTP 429 with a `retry_after` value.

## Error Responses

All errors are returned as JSON with the following structure:

```json
{
    "error": "Error message description"
}
```

Common HTTP status codes:

| Code | Description |
|------|-------------|
| 200 | Success |
| 400 | Bad request |
| 401 | Unauthorized (invalid or expired token) |
| 403 | Forbidden (insufficient permissions) |
| 429 | Too many requests (rate limited) |
| 500 | Internal server error |

## Multi-Entity Support

If your Dolibarr installation uses the MultiCompany module, you can:

1. Call `GET /index` to retrieve the list of available entities
2. Specify the `entity` parameter when logging in

---

To generate this documentation:

```bash
apidoc -i api/ -o docs/api/
```
