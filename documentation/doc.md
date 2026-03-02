<a name="top"></a>
# SmartAuth for Dolibarr v1.0.0

Here is all the documentation for SmartAuth API

# Introduction

<h1>SmartAuth API</h1>
<p>SmartAuth is an authentication module for Dolibarr ERP/CRM that provides JWT-based authentication with refresh token support.</p>
<h2>Authentication</h2>
<p>Most API endpoints require authentication via JWT Bearer token.</p>
<h3>How to authenticate</h3>
<ol>
<li>Call <code>POST /login</code> with your credentials to obtain an access token and refresh token</li>
<li>Include the access token in subsequent requests via the <code>Authorization</code> header:<pre><code>Authorization: Bearer <token_id>|<jwt>
</code></pre>
</li>
<li>When the access token expires, use <code>GET /refresh</code> with your refresh token to obtain new tokens</li>
</ol>
<h3>Token Types</h3>
<ul>
<li><strong>Access Token</strong>: Short-lived token for API requests</li>
<li><strong>Refresh Token</strong>: Long-lived token used to obtain new access tokens</li>
</ul>
<h3>Required Headers</h3>
<table>
<thead>
<tr>
<th>Header</th>
<th>Description</th>
<th>Required</th>
</tr>
</thead>
<tbody>
<tr>
<td><code>Authorization</code></td>
<td>Bearer token for authenticated routes</td>
<td>Yes (protected routes)</td>
</tr>
<tr>
<td><code>X-DeviceId</code></td>
<td>Unique device identifier (UUID or SHA256 hash)</td>
<td>Yes</td>
</tr>
<tr>
<td><code>Content-Type</code></td>
<td>Must be <code>application/json</code> for POST/PUT requests</td>
<td>Yes</td>
</tr>
</tbody>
</table>
<h2>Rate Limiting</h2>
<p>The API implements rate limiting to protect against brute force attacks:</p>
<ul>
<li><strong>IP-based</strong>: 10 attempts per 5 minutes (configurable)</li>
<li><strong>Username-based</strong>: 5 attempts per 15 minutes (configurable)</li>
</ul>
<p>When rate limited, the API returns HTTP 429 with a <code>retry_after</code> value.</p>
<h2>Error Responses</h2>
<p>All errors are returned as JSON with the following structure:</p>
<pre><code class="language-json">{
    "error": "Error message description"
}
</code></pre>
<p>Common HTTP status codes:</p>
<table>
<thead>
<tr>
<th>Code</th>
<th>Description</th>
</tr>
</thead>
<tbody>
<tr>
<td>200</td>
<td>Success</td>
</tr>
<tr>
<td>400</td>
<td>Bad request</td>
</tr>
<tr>
<td>401</td>
<td>Unauthorized (invalid or expired token)</td>
</tr>
<tr>
<td>403</td>
<td>Forbidden (insufficient permissions)</td>
</tr>
<tr>
<td>429</td>
<td>Too many requests (rate limited)</td>
</tr>
<tr>
<td>500</td>
<td>Internal server error</td>
</tr>
</tbody>
</table>
<h2>Multi-Entity Support</h2>
<p>If your Dolibarr installation uses the MultiCompany module, you can:</p>
<ol>
<li>Call <code>GET /index</code> to retrieve the list of available entities</li>
<li>Specify the <code>entity</code> parameter when logging in</li>
</ol>
<hr>
<p>To generate this documentation:</p>
<pre><code class="language-bash">apidoc -i api/ -o docs/api/
</code></pre>


# Table of contents

- [Auth](#Auth)
  - [Check token validity](#Check-token-validity)
  - [List available entities](#List-available-entities)
  - [Login](#Login)
  - [Logout](#Logout)
  - [Refresh tokens](#Refresh-tokens)
- [Device](#Device)
  - [Manage device](#Manage-device)
- [ObjectDocument](#ObjectDocument)
  - [List documents](#List-documents)
  - [Download document (base64)](#Download-document-base64)
  - [Download document (binary)](#Download-document-binary)
  - [Bundle download (ZIP)](#Bundle-download-ZIP)

___


# <a name='Auth'></a> Auth

## <a name='Check-token-validity'></a> Check token validity
[Back to top](#top)

<p>Check if your token is still valid. Redirects to refresh endpoint.</p>

```
GET /ping
```

### Headers - `Header`

| Name    | Type      | Description                          |
|---------|-----------|--------------------------------------|
| Authorization | `String` | <p>Bearer token</p> |
| X-DeviceId | `String` | <p>Unique device identifier</p> |

## <a name='List-available-entities'></a> List available entities
[Back to top](#top)

<p>Get the list of available Dolibarr entities. Use this endpoint before login if your Dolibarr uses the MultiCompany module. This allows the user to select the correct entity before authentication.</p>

```
GET /index
```
### Success response

#### Success response - `Success 200`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| entities | `Object[]` | <p>List of available entities</p> |
| entities.id | `Number` | <p>Entity ID</p> |
| entities.label | `String` | <p>Entity name</p> |

### Success response example

#### Success response example - `Success-Response:`

```json
HTTP/1.1 200 OK
{
    "entities": [
        {"id": 1, "label": "Main Company"},
        {"id": 2, "label": "Branch Office"}
    ]
}
```

## <a name='Login'></a> Login
[Back to top](#top)

<p>Authenticate user with email/password and obtain JWT tokens. On success, returns both an access token and a refresh token. Rate limiting is applied per IP and per username.</p>

```
POST /login
```

### Headers - `Header`

| Name    | Type      | Description                          |
|---------|-----------|--------------------------------------|
| X-DeviceId | `String` | <p>Unique device identifier (UUID or SHA256 hash)</p> |
| Content-Type | `String` | <p>Must be application/json</p> |

### Request Body

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| email | `String` | <p>User email or login</p> |
| password | `String` | <p>User password</p> |
| entity | `Number` | **optional** <p>Dolibarr entity ID (for MultiCompany)</p>_Default value: 1_<br> |
| rememberMe | `Number` | **optional** <p>Remember me flag</p>_Default value: 0_<br> |
### Success response

#### Success response - `Success 200`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| user | `String` | <p>User email or login</p> |
| userid | `Number` | <p>User ID</p> |
| entity | `Number` | <p>Entity ID</p> |
| token | `String` | <p>Access token (legacy, same as access_token)</p> |
| access_token | `String` | <p>JWT access token</p> |
| refresh_token | `String` | <p>JWT refresh token</p> |
| expires_in | `Number` | <p>Access token lifetime in seconds</p> |
| token_type | `String` | <p>Token type (Bearer)</p> |
| devices_choice | `Object[]` | **optional**<p>List of known devices for this user (if device is new)</p> |
| rememberMe | `Number` | <p>Remember me flag</p> |

### Success response example

#### Success response example - `Success-Response:`

```json
HTTP/1.1 200 OK
{
    "user": "user@example.com",
    "userid": 3,
    "entity": 1,
    "token": "123|eyJ0eXAiOiJKV1Q...",
    "access_token": "123|eyJ0eXAiOiJKV1Q...",
    "refresh_token": "124|eyJ0eXAiOiJKV1Q...",
    "expires_in": 3600,
    "token_type": "Bearer",
    "devices_choice": null,
    "rememberMe": 0
}
```

### Error response

#### Error response - `401`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| AccessDenied |  | <p>Invalid credentials</p> |

#### Error response - `429`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| TooManyRequests |  | <p>Rate limit exceeded</p> |

### Error response example

#### Error response example - `Rate-Limit-Response:`

```json
HTTP/1.1 429 Too Many Requests
{
    "error": "Too many attempts. Please try again later.",
    "retry_after": 180
}
```

## <a name='Logout'></a> Logout
[Back to top](#top)

<p>Logout the user and revoke all tokens in the current token family. This invalidates both access and refresh tokens.</p>

```
POST /logout
```

### Headers - `Header`

| Name    | Type      | Description                          |
|---------|-----------|--------------------------------------|
| Authorization | `String` | <p>Bearer access_token</p> |
| X-DeviceId | `String` | <p>Unique device identifier</p> |
### Success response

#### Success response - `Success 200`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| user | `String` | <p>Empty string (logged out)</p> |
| token | `String` | <p>Empty string (revoked)</p> |

### Success response example

#### Success response example - `Success-Response:`

```json
HTTP/1.1 200 OK
{
    "user": "",
    "token": ""
}
```

## <a name='Refresh-tokens'></a> Refresh tokens
[Back to top](#top)

<p>Use the refresh token to obtain a new access token and refresh token pair. The current refresh token is invalidated after use (token rotation).</p>

```
GET /refresh
```

### Headers - `Header`

| Name    | Type      | Description                          |
|---------|-----------|--------------------------------------|
| Authorization | `String` | <p>Bearer refresh_token (format: token_id|jwt)</p> |
| X-DeviceId | `String` | <p>Unique device identifier</p> |
### Success response

#### Success response - `Success 200`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| access_token | `String` | <p>New JWT access token</p> |
| refresh_token | `String` | <p>New JWT refresh token</p> |
| expires_in | `Number` | <p>Access token lifetime in seconds</p> |
| token_type | `String` | <p>Token type (Bearer)</p> |

### Success response example

#### Success response example - `Success-Response:`

```json
HTTP/1.1 200 OK
{
    "access_token": "123|eyJ0eXAiOiJKV1Q...",
    "refresh_token": "124|eyJ0eXAiOiJKV1Q...",
    "expires_in": 3600,
    "token_type": "Bearer"
}
```

### Error response

#### Error response - `401`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| RefreshTokenRequired |  | <p>Refresh token is missing</p> |
| InvalidTokenFormat |  | <p>Token format is invalid</p> |
| InvalidTokenPayload |  | <p>Token payload is invalid</p> |
| SecurityViolation |  | <p>Token replay attack detected, all sessions revoked</p> |
| MaxRefreshExceeded |  | <p>Maximum refresh limit reached, login required</p> |

# <a name='Device'></a> Device

## <a name='Manage-device'></a> Manage device
[Back to top](#top)

<p>Manage device association for the authenticated user. Two use cases:</p> <ol> <li>Same UUID: Update the device name/label</li> <li>Different UUID: Switch to an existing device (generates new tokens)</li> </ol>

```
POST /device
```

### Headers - `Header`

| Name    | Type      | Description                          |
|---------|-----------|--------------------------------------|
| Authorization | `String` | <p>Bearer access_token</p> |
| X-DeviceId | `String` | <p>Current device UUID</p> |

### Request Body

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| uuid | `String` | <p>Device UUID to associate</p> |
| label | `String` | **optional** <p>Device name/label (for naming the device)</p> |
### Success response

#### Success response - `Success 200`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| message | `String` | **optional**<p>Status message</p> |
| token | `String` | **optional**<p>New access token (if device switched)</p> |
| access_token | `String` | **optional**<p>New access token (if device switched)</p> |
| refresh_token | `String` | **optional**<p>New refresh token (if device switched)</p> |
| expires_in | `Number` | **optional**<p>Token lifetime in seconds (if device switched)</p> |
| token_type | `String` | **optional**<p>Token type (if device switched)</p> |

### Success response example

#### Success response example - `Success-Response (same device, name updated):`

```json
HTTP/1.1 200 OK
{
    "message": "update device name : success"
}
```

#### Success response example - `Success-Response (device switched):`

```json
HTTP/1.1 200 OK
{
    "token": "125|eyJ0eXAiOiJKV1Q...",
    "access_token": "125|eyJ0eXAiOiJKV1Q...",
    "refresh_token": "126|eyJ0eXAiOiJKV1Q...",
    "expires_in": 3600,
    "token_type": "Bearer",
    "message": "please use this new token"
}
```

# <a name='ObjectDocument'></a> ObjectDocument

## <a name='List-documents'></a> List documents
[Back to top](#top)

<p>Lists all documents attached to a Dolibarr object. Returns metadata including the ECM share hash for downloading. If a file has no entry in <code>llx_ecm_files</code>, one is created automatically with a share hash.</p>

```
GET /object/{type}/{id}/documents
```

### Headers - `Header`

| Name    | Type      | Description                          |
|---------|-----------|--------------------------------------|
| Authorization | `String` | <p>Bearer access_token</p> |

### Parameters - `Parameter`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| type | `String` | <p>Object type: <code>product</code>, <code>thirdparty</code>, <code>project</code>, <code>intervention</code>, <code>category</code></p> |
| id | `Number` | <p>Object ID (rowid)</p> |

### Query Parameters

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| since | `String` | **optional** <p>ISO timestamp - only return files modified after this date</p> |

### Success response

#### Success response - `Success 200`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| documents | `Object[]` | <p>List of documents</p> |
| documents.id | `String` | <p>Stable hash ID (md5 of type+id+path, 8 chars)</p> |
| documents.ecm_id | `Number` | <p>Dolibarr ECM file ID (<code>llx_ecm_files.rowid</code>)</p> |
| documents.share | `String` | <p>Share hash for download (use with <code>?q=</code>)</p> |
| documents.object_id | `Number` | <p>Parent object ID</p> |
| documents.filename | `String` | <p>File name</p> |
| documents.relative_path | `String` | <p>Path relative to object directory</p> |
| documents.mime_type | `String` | <p>MIME type</p> |
| documents.size | `Number` | <p>File size in bytes</p> |
| documents.updated_at | `String` | <p>Last modification date (ISO 8601)</p> |
| documents.type | `String` | <p><code>image</code>, <code>pdf</code>, or <code>other</code></p> |
| server_time | `String` | <p>Server time (ISO 8601)</p> |

### Success response example

#### Success response example - `Success-Response:`

```json
HTTP/1.1 200 OK
{
    "documents": [
        {
            "id": "41e2e5fd",
            "ecm_id": 1234,
            "share": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
            "object_id": 31,
            "filename": "photo_31.jpg",
            "relative_path": "photos/photo_31.jpg",
            "mime_type": "image/jpeg",
            "size": 82263,
            "updated_at": "2026-03-02T09:23:09+01:00",
            "type": "image"
        }
    ],
    "server_time": "2026-03-02T09:29:16+01:00"
}
```

## <a name='Download-document-base64'></a> Download document (base64)
[Back to top](#top)

<p>Downloads a document as base64-encoded JSON response. Two modes are available:</p>
<ul>
<li><strong>Share hash (recommended)</strong>: use <code>?q=share_hash</code> from the list response. Avoids URL encoding issues with subdirectory paths.</li>
<li><strong>Legacy path</strong>: path in URL segment. Only works for simple filenames without subdirectories.</li>
</ul>

```
GET /object/{type}/{id}/document?q={share_hash}
GET /object/{type}/{id}/document/{path}
```

### Headers - `Header`

| Name    | Type      | Description                          |
|---------|-----------|--------------------------------------|
| Authorization | `String` | <p>Bearer access_token</p> |

### Parameters - `Parameter`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| type | `String` | <p>Object type</p> |
| id | `Number` | <p>Object ID (rowid)</p> |
| path | `String` | **optional** <p>Relative path (legacy mode, URL segment)</p> |

### Query Parameters

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| q | `String` | **optional** <p>Share hash from <code>llx_ecm_files</code> (recommended mode)</p> |

### Success response

#### Success response - `Success 200`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| filename | `String` | <p>File name</p> |
| content-type | `String` | <p>MIME type</p> |
| filesize | `Number` | <p>File size in bytes</p> |
| content | `String` | <p>Base64-encoded file content</p> |
| encoding | `String` | <p>Always <code>base64</code></p> |

### Success response example

#### Success response example - `Success-Response:`

```json
HTTP/1.1 200 OK
{
    "filename": "photo_31.jpg",
    "content-type": "image/jpeg",
    "filesize": 82263,
    "content": "/9j/4AAQSkZJRg...",
    "encoding": "base64"
}
```

### Error response

#### Error response - `404`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| error | `String` | <p>Document not found (invalid share hash or missing file)</p> |

#### Error response - `413`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| error | `String` | <p>File too large for base64 (>50MB), use binary mode</p> |

## <a name='Download-document-binary'></a> Download document (binary)
[Back to top](#top)

<p>Downloads a document as a binary stream. More efficient for large files. Supports the same two modes as the base64 endpoint.</p>

```
GET /object/{type}/{id}/document/binary?q={share_hash}
GET /object/{type}/{id}/document/{path}/binary
```

### Headers - `Header`

| Name    | Type      | Description                          |
|---------|-----------|--------------------------------------|
| Authorization | `String` | <p>Bearer access_token</p> |

### Parameters - `Parameter`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| type | `String` | <p>Object type</p> |
| id | `Number` | <p>Object ID (rowid)</p> |
| path | `String` | **optional** <p>Relative path (legacy mode)</p> |

### Query Parameters

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| q | `String` | **optional** <p>Share hash from <code>llx_ecm_files</code> (recommended)</p> |

### Success response

The response is a binary stream with appropriate `Content-Type`, `Content-Disposition`, and `Content-Length` headers.

## <a name='Bundle-download-ZIP'></a> Bundle download (ZIP)
[Back to top](#top)

<p>Downloads multiple documents at once as a ZIP archive. Uses share hashes from the list endpoint to identify files. Ideal for offline sync to batch-download all documents in a single request instead of N individual calls.</p>

<p>The ZIP contains a <code>manifest.json</code> with metadata and a <code>files/</code> directory where each file is named by its share hash. Files are stored without compression (STORE method) since images and PDFs are already compressed.</p>

```
POST /object/documents/bundle
```

### Headers - `Header`

| Name    | Type      | Description                          |
|---------|-----------|--------------------------------------|
| Authorization | `String` | <p>Bearer access_token</p> |
| Content-Type | `String` | <p>application/json</p> |

### Body Parameters

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| shares | `String[]` | <p>Array of share hashes to download (max 500)</p> |
| max_file_size | `Number` | **optional** <p>Max individual file size in bytes (default/max: 5 MB)</p> |

### Success response

#### Success response - `Success 200`

The response is a ZIP binary stream (`Content-Type: application/zip`).

**ZIP structure:**

```
bundle.zip
├── manifest.json
└── files/
    ├── a1b2c3d4e5f6...    (file content, named by share hash)
    ├── g7h8i9j0k1l2...
    └── ...
```

**manifest.json structure:**

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| included | `Object[]` | <p>Files successfully included in the ZIP</p> |
| included.share | `String` | <p>Share hash</p> |
| included.filename | `String` | <p>Original filename</p> |
| included.mime_type | `String` | <p>MIME type</p> |
| included.size | `Number` | <p>File size in bytes</p> |
| oversized | `Object[]` | <p>Files skipped because they exceed max_file_size</p> |
| remaining | `String[]` | <p>Share hashes skipped because total bundle size limit (100 MB) was reached</p> |
| errors | `Object[]` | <p>Files that could not be resolved or found</p> |
| errors.share | `String` | <p>Share hash</p> |
| errors.error | `String` | <p><code>not_found</code> or <code>file_missing</code></p> |
| server_time | `Number` | <p>Unix timestamp</p> |

### Success response example

#### Success response example - `manifest.json:`

```json
{
    "included": [
        {
            "share": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6",
            "filename": "photo_31.jpg",
            "mime_type": "image/jpeg",
            "size": 82263
        }
    ],
    "oversized": [],
    "remaining": [],
    "errors": [
        {
            "share": "invalidhash123",
            "error": "not_found"
        }
    ],
    "server_time": 1740900000
}
```

### Error response

#### Error response - `400`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| error | `String` | <p>Missing or empty shares array, or too many shares (max 500)</p> |

### Limits

| Limit | Value | Description |
|-------|-------|-------------|
| Max shares per request | 500 | Maximum number of share hashes in the `shares` array |
| Max individual file size | 5 MB | Files larger than this are listed in `oversized` |
| Max total bundle size | 100 MB | Once reached, remaining shares go to `remaining` |

