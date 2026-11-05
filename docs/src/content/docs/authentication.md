---
title: "Authentication and permissions"
description: "API keys, JWT, user login (password and Google), roles, per-resource permissions and row-level security."
sidebar:
  label: "Authentication"
---

## Roles

Every request runs as a **role**:

| Role | Who | Permissions |
|---|---|---|
| `anon` | requests without credentials | from the resource / default permissions (none by default) |
| `authenticated` | the default role of API keys and JWTs | from the resource / default permissions (all four by default) |
| `service_role` | trusted servers | **bypasses every permission check and every `scope()`** |
| any name | custom roles (`editor`, `accounting` …) from API keys or JWT claims | from the resource / default permissions |

## Authentication modes

Setting `auth_mode` (Settings → Authentication):

| Mode | Accepts |
|---|---|
| `api_key` (default) | API keys |
| `jwt` | user tokens: JWT bearer tokens and [user login](#user-login) session tokens |
| `any` | both |
| `none` | nothing is checked: **every request is `service_role`**. Development only. |

Credentials are read from, in order:

1. `apikey: <key>` or `X-API-Key: <key>`
2. `Authorization: Bearer <api key, session token or JWT>` - values starting with `gr_` are API keys, `grs_`
   session tokens of the user login, anything else a JWT
3. `?apikey=<key>` - only when `allow_query_apikey` is enabled (keys leak into access logs)

Like Supabase, a client may send the public `anon` key in `apikey` **and** a user JWT in `Authorization`; the JWT
then defines the role. Invalid credentials are always a `401` (they never fall back to `anon`).

## API keys

- Created by the setup wizard (`anon` + `service_role`), on the **API keys** page, with
  `php spark grocery-restable:key <name> [role]` or `$api->store()->createApiKey($name, $role)`.
- Format: `gr_` + 48 hex characters (192 bits of randomness). Only the SHA-256 hash is stored; the key is shown once.
- Revoke or delete keys at any time; *last used* is tracked (updated at most once a minute).

Typical setup: ship the `anon` key in public clients (with `anon` permissions limited to public data), keep the
`service_role` key on servers, give integrations their own key with a dedicated role.

## JWT

Grocery Restable verifies HMAC JWTs (`HS256`, `HS384`, `HS512`) signed with the configured secret
(Settings → JWT, or `jwtSecret` in the bootstrap config / `groceryrestable.jwtSecret` in `.env`). They come from the
built-in [user login](#user-login) or from your application (your own login endpoint, CodeIgniter Shield, an identity
provider using HMAC).

Checks: signature (`alg: none` and asymmetric algorithms are rejected), `exp`, `nbf` (60 s leeway), optional
`iss` and `aud` (settings `jwt_issuer`, `jwt_audience`).

The role comes from the claim named by `jwt_role_claim` (default `role`, dot notation allowed such as
`app_metadata.role`), falling back to `jwt_default_role` (`authenticated`). Role names must match
`[A-Za-z_][A-Za-z0-9_-]*`.

Issue a token in PHP (e.g. after your own login):

```php
use GroceryRestable\Support\Jwt;

$token = Jwt::encode([
    'sub' => (string) $user->id,
    'role' => 'authenticated',
    'iat' => time(),
    'exp' => time() + 3600,
], getenv('GROCERY_RESTABLE_JWT_SECRET'));
```

The GUI can generate test tokens (API keys page) for any role.

## User login

Sign the users of **a table of your own database** in through the API, with a password or with Google, and get
tokens that work with permissions and `scope()` like any other JWT. Configure it in **Settings → User login**; the
authentication mode must accept user tokens (`any` or `jwt`).

| Endpoint | Body | Answer |
|---|---|---|
| `GET /auth` | | enabled methods: `{password, google: {client_id}, strategy}` (for login screens) |
| `POST /auth/login` | `{identity, password}` (`email` / `username` accepted as aliases of `identity`) | tokens + user |
| `POST /auth/google` | `{id_token}` (or `credential`), or `{code, redirect_uri?, code_verifier?}` | tokens + user |
| `POST /auth/refresh` | `{refresh_token}` | new tokens + user (JWT strategy) |
| `POST /auth/logout` | `{refresh_token?, all?}` with the bearer token | `204` |
| `GET /auth/me` | | the signed in user, read again from the table |

```json
{
  "token_type": "Bearer",
  "access_token": "eyJhbGciOi...",
  "expires_in": 900,
  "expires_at": 1793000000,
  "refresh_token": "grr_6f1c...",
  "refresh_expires_at": 1795592000,
  "user": { "id": 1, "email": "jane@example.com", "role": "editor" }
}
```

The password column and the "never returned" columns are removed from `user`. Answers carry
`Cache-Control: no-store`. A stale or invalid token in the request does not block `login`, `google` and `refresh`.

### Users table

| Setting | Default | |
|---|---|---|
| Connection, table | default connection, `users` | |
| Id column | `id` | becomes the `sub` claim, `$auth->userId()` |
| Identity columns | `email` | tried in order (e.g. `email, username`); text columns compare case-insensitively (on PostgreSQL add an index on `lower(email)`); a value matching two rows never signs in |
| Password column | `password` | hash, see [Password hashing](#password-hashing) |
| Role column | (none) | empty value = `jwt_default_role`. `service_role` is refused (unless `jwt_allow_service_role`), invalid role names fail |
| Active column | (none) | `0`, `false`, empty or `NULL` = login refused (`403 account_disabled`) |
| Columns copied into the token | (none) | e.g. `tenant_id`, read with `$auth->claim('tenant_id')` in `scope()` |
| Columns never returned | (none) | |

The role is written at the `jwt_role_claim` path, so tokens issued here pass the same checks as external ones.

### Tokens: JWT or session

| Strategy | Access token | Renewal | Revocation | Pick it when |
|---|---|---|---|---|
| `jwt` (default) | HMAC JWT, `auth_access_ttl` (15 min) | `POST /auth/refresh` with the refresh token | refresh tokens at once, access tokens expire within `auth_access_ttl` | several servers, stateless verification, mobile apps |
| `session` | opaque `grs_...` token, `auth_session_ttl` | none (sign in again) | immediate: every request checks the session | one server, instant logout / ban matters most |

- Refresh tokens are **single use**: every refresh returns a new one. Presenting an already used refresh token later
  than 10 seconds after its rotation (two tabs refreshing at once are tolerated) is treated as theft and revokes the
  session.
- A refresh reads the user again: deleted or disabled users lose access, role and claim changes apply.
- Session and refresh token lifetimes (`auth_session_ttl`, 30 days) are absolute, counted from the login.
- Tokens are stored as SHA-256 hashes in the SQLite configuration store (`auth_sessions`), which also means: with
  several servers, share the storage directory or use the `jwt` strategy (only refresh needs the store).
- `$api->auth()->revokeSessions($userId)` signs a user out everywhere (e.g. after a password change);
  `POST /auth/logout {"all": true}` does the same for the caller.

### Password hashing

New hashes use **argon2id** (default), argon2i or bcrypt with `password_hash()`, so they stay readable by
`password_verify()`, CodeIgniter Shield, Laravel and any other PHP code on the same table.

| Setting | Default | |
|---|---|---|
| `auth_password_algorithm` | `argon2id` | falls back to bcrypt on servers without argon2 |
| `auth_password_cost` | `12` | bcrypt, 10 to 15 |
| `auth_password_memory`, `auth_password_time` | `65536` KiB, `4` | argon2, minimum 19456 KiB / 2 iterations (OWASP) |
| `auth_password_rehash` | on | after a successful login, hashes of another algorithm or weaker parameters are replaced |
| `auth_password_legacy` | none | verify-only `md5`, `sha1`, `sha256`, `sha512` hex digests, for migrating old tables |

Bcrypt ignores everything after 72 bytes, so longer passwords are rejected when hashing with bcrypt; passwords over
4096 bytes are always rejected. `password` field types of endpoints (`->fieldType('password', 'password')`) use the
same settings, so users created through the API can sign in.

Migrating a table with unsalted MD5 hashes: enable `md5` as legacy digest, users are rehashed with argon2id at their
next login; disable `md5` once the old hashes are gone. Other formats (salted, framework specific) are verified with a
callback:

```php
$api->auth()->verifyLegacyPasswordUsing(function (string $password, string $hash): ?bool {
    if (! str_starts_with($hash, 'sha1:')) {
        return null;                         // not my format: use the built-in checks
    }
    [, $salt, $digest] = explode(':', $hash);

    return hash_equals($digest, sha1($salt . $password));
});

$hash = $api->auth()->hashPassword($plain);  // registration, seeders, password change
```

### Brute force protection

Failed logins are counted per account (`auth_max_attempts`, 5) and per IP (`auth_max_attempts_ip`, 20) within
`auth_attempts_window` (15 min). Over the limit, logins answer `429 too_many_attempts` with `Retry-After`, even with
the right password. Unknown accounts and wrong passwords give the same `401 invalid_credentials` in the same time
(a dummy hash is verified). The global [rate limit](rate-limiting.md) applies too.

### Google sign-in

1. Create an OAuth client (type *Web application*, plus Android / iOS clients for mobile apps) in the Google Cloud
   console and enter the client ids in **Settings → User login → Google**.
2. On the client, get an **ID token** with [Sign in with Google](https://developers.google.com/identity/gsi/web) (button
   or One Tap: `response.credential`) or the Android / iOS SDKs, and send it to `POST /auth/google`.
3. Or use the authorization **code** flow (Google Identity Services popup with `ux_mode: 'popup'`, or your own redirect):
   send `{code}` (popup) or `{code, redirect_uri, code_verifier}`. This needs the client secret.

The server verifies the RS256 signature against Google's keys (cached as long as Google allows), the issuer, the
audience (one of your client ids), the expiry and, when configured, the Google Workspace domain (`hd`).

Matching users, in order:

1. the **Google id column** (`google_id`, stores Google's `sub`; recommended, emails can change);
2. the **email column**, only when the Google email is verified and `auth_google_link_by_email` is on; the Google id
   is then stored. A user already linked to another Google account answers `409`;
3. otherwise, with `auth_google_signup`, a new row: email, Google id and the `auth_google_signup_fields` mapping
   (`name=name, avatar=picture`). A NOT NULL password column gets a value that never verifies.

Without a match the answer is `403 account_not_found`.

> **Caution:** linking by email trusts that the email of an existing row belongs to its owner. If your application
> lets people register without verifying their email, disable linking or check it in `beforeLogin()`, otherwise
> someone could register with another person's address and take over the account when that person signs in with Google.

### Customizing in code

```php
use GroceryRestable\Exception\ApiException;

$api->auth()
    // extra claims (reserved claims such as sub, exp, role are ignored)
    ->claims(fn (array $user, string $provider) => ['tenant_id' => (int) $user['tenant_id']])
    // compute the role instead of reading a column
    ->role(fn (array $user) => $user['is_admin'] ? 'admin' : 'authenticated')
    // refuse a login after the credentials were verified
    ->beforeLogin(function (array $user, string $provider) {
        if ($user['email_verified_at'] === null) {
            throw new ApiException('Verify your email first.', 403, 'email_not_verified');
        }
    })
    // audit, last login...
    ->afterLogin(fn (array $user, string $provider, array $tokens, $request) => null)
    // the user object returned by /auth
    ->userPayload(fn (array $user) => ['id' => $user['id'], 'name' => $user['name']])
    // columns of users created by Google sign-in
    ->googleSignup(fn (array $google, array $row) => $row + ['locale' => $google['locale'] ?? 'en']);
```

Your own flows (OTP, magic links, another provider) can issue the same tokens, e.g. from an RPC:

```php
$api->rpc('verify-otp', function (array $params, ActionContext $ctx) use ($api) {
    $user = /* check the code, load the user row */;

    return $api->auth()->issueTokens($user, 'otp', $ctx->request);
}, ['roles' => ['*']]);
```

From the browser, see [JavaScript SDK → User login](javascript-sdk.md#user-login).

## Permissions

A resource grants operations per role:

```php
$api->resource('products')->setPermissions([
    'anon' => ['select'],
    'authenticated' => ['select'],
    'editor' => ['select', 'insert', 'update'],
    'admin' => ['select', 'insert', 'update', 'delete'],
]);

$api->resource('news')->setPermissions('*', ['select']);   // "*" = every role, including anon
$api->resource('internal')->public();                         // everything for everybody
```

| Permission | Allows |
|---|---|
| `select` | list, read, `_options`, `_schema`, file download, embedding as a target, CSV export |
| `insert` | create, bulk create, multipart create, clone (with `select`) |
| `update` | update, replace, bulk update, uploads, upsert of existing rows, actions (default) |
| `delete` | delete, bulk delete |

Resources without `setPermissions()` use the **default permissions** (Settings): by default `anon` gets nothing and
`authenticated` gets everything. Auto-exposed resources always use the default permissions.

Missing permission → `401` for `anon`, `403` for other roles. Disabled operations (`unsetDelete()` …) → `405` for
every role, including `service_role`.

## Row-level security

`scope()` adds conditions computed per request to every read, update and delete (and to embedded rows of that
resource):

```php
use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Resource\Conditions;

$api->resource('notes')
    ->setPermissions('authenticated', ['select', 'insert', 'update', 'delete'])
    ->scope(fn (Conditions $q, AuthContext $auth) => $q->where('owner_id', (string) $auth->userId()))
    ->fieldType('owner_id', 'readonly')
    ->callbackBeforeInsert(fn (array $data, AuthContext $auth) => ['owner_id' => $auth->userId()] + $data);
```

- Rows outside the scope behave as if they did not exist (`404`), also for updates and deletes.
- `AuthContext` exposes `role`, `method` (`anonymous`, `api_key`, `jwt`, `session`, `internal`), `claims`,
  `claim($name)`, `userId()` (`sub` claim), `apiKeyId`, `isService()`, `isAnonymous()`.
- `service_role` bypasses scopes; static `where()` conditions of the resource always apply.

Multi-tenant example:

```php
->scope(function (Conditions $q, AuthContext $auth) {
    $q->where('tenant_id', (int) $auth->claim('tenant_id', 0));
})
->fieldType('tenant_id', 'invisible')
->callbackBeforeInsert(fn (array $d, AuthContext $a) => ['tenant_id' => (int) $a->claim('tenant_id')] + $d)
```

## Protecting routes with your framework

When a CodeIgniter filter already authenticates the user (session login, Shield), you can let the filter decide and
open the resource to every caller:

```php
// app/Config/GroceryRestable.php
public array $apiFilters = ['session'];            // e.g. CodeIgniter Shield's session filter

// and per resource
$api->resource('orders')->public();
```

Inside callbacks you can still read the framework user (`auth()->user()` with Shield).

## Rate limiting

Every API request is counted per client (API key, JWT user or IP) and limited; failed authentications count against
the IP. See [Rate limiting](rate-limiting.md). Admin logins are throttled separately (5 failures / 15 min per IP),
user logins too (see [Brute force protection](#brute-force-protection)).

## CORS

Settings → CORS: allowed origins (`*` by default), preflight max age. Credentials are tokens, not cookies, so
`Access-Control-Allow-Credentials` is not sent. Restrict origins in production when only known web apps call the API.
