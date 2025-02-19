<a name="top"></a>
# SmartAuth for Dolibarr v1.0.0

Here is all the documentation for SmartAuth API

# Introduction

<h1>Introduction</h1>
<p>To generate this documentation:</p>
<pre><code class="language-bash">make apidoc
</code></pre>


# Table of contents

- [Auth](#Auth)
  - [List of dolibarr entities](#List-of-dolibarr-entities)
  - [Login](#Login)
  - [Logout](#Logout)

___


# <a name='Auth'></a> Auth

## <a name='List-of-dolibarr-entities'></a> List of dolibarr entities
[Back to top](#top)

<p>Get the list of dolibarr entities before login then you can make a login request on the right dolibarr entity if your dolibarr use multicompany module</p>

```
GET /login
```
### Success response

#### Success response - `Success 200`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| entities | `Array` | <p>array of dolibarr available entities</p> |

## <a name='Login'></a> Login
[Back to top](#top)

<p>Try to log into dolibarr with login / password and in case of success generate a token for that app / session</p>

```
POST /login
```

### Request Body

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| email | `String` | <p>Mandatory dolibarr user name (email)</p> |
| password | `String` | <p>Mandatory user password</p> |
| entity | `Number` | <p>Mandatory dolibarr entity</p> |
### Success response

#### Success response - `Success 200`

| Name     | Type       | Description                           |
|----------|------------|---------------------------------------|
| user | `String` | <p>User login</p> |
| userid | `Number` | <p>User ID</p> |
| token | `String` | <p>Session JWT to use for next requests as Bearer Auth Token (JWT)</p> |

### Success response example

#### Success response example - `Success-Response:`

```json
HTTP/1.1 200 OK
{
    "statusCode": 200,
    "data": {
        "user": "eric@cap-rel.fr",
        "userid": "3",
        "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUz88NiJ9.eyJsb2dpbiI622RsYyIsImVu88l0eSI6MH0._XWcHLf999kMqkP65dgXcbkqT522W9zbdUiIA3BU0pI"
    }
 }
```

## <a name='Logout'></a> Logout
[Back to top](#top)

<p>Logout and close session</p>

```
POST /logout
```

