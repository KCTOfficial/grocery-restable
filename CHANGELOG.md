# Changelog

## 1.2.0 - 2026-11-05

User login for the API on your own users table: password login with modern hashing and brute force protection,
Sign in with Google, JWT or session tokens, hooks to customize the flow, and `gr.auth` in the JavaScript SDK.

### Added

- **User login** (`/auth`) on a table of your own database, configured in **Settings > User login**:
  - password login (`POST /auth/login`, identity matched in several columns, optional role, active and claim columns);
  - **Sign in with Google** (`POST /auth/google`): ID tokens verified locally (RS256 against Google's cached keys,
    issuer, audience, expiry, Workspace domain) or authorization codes; users found by Google id, linked by verified
    email or created on first sign-in;
  - two token strategies: short lived **JWT + rotating refresh token** with reuse detection (`POST /auth/refresh`),
    or opaque, instantly revocable **session tokens** (`grs_...`); `POST /auth/logout` (`all` for every device),
    `GET /auth/me`, `GET /auth` (sign-in methods);
  - **password hashing** with argon2id (default), argon2i or bcrypt and configurable parameters, rehash on login,
    verify-only legacy digests (md5, sha1, sha256, sha512) to migrate old tables, custom verifier callback;
  - brute force protection per account and per IP (`429` with `Retry-After`), equal answers and timing for unknown
    users and wrong passwords;
  - hooks: `$api->auth()->claims()`, `role()`, `beforeLogin()`, `afterLogin()`, `userPayload()`, `googleSignup()`,
    `verifyLegacyPasswordUsing()`, plus `issueTokens()`, `hashPassword()` and `revokeSessions()` for custom flows;
  - OpenAPI paths for the enabled endpoints.
- JavaScript SDK: `gr.auth` (`login`, `loginWithGoogle`, `refresh`, `logout`, `me`, `methods`), automatic token
  renewal (before expiry and once after a `401`), `LocalAuthStore` persisted in `localStorage`, `noToken` /
  `noRefresh` request options.
- `AuthContext::$method` can be `session`.

### Changed

- `password` field types of endpoints are hashed with the user login hashing settings (argon2id by default instead
  of bcrypt). Existing hashes keep working with `password_verify()`.
- Authentication mode labels: `jwt` / `any` accept "user tokens" (JWTs and user login sessions).

### Upgrade notes

- `auth` is now a reserved name (like `rpc` and `storage`): a code resource named `auth` throws at registration and
  a table named `auth` is no longer auto exposed. Rename the endpoint (`$api->resource('auth_items', 'auth')`).
- The SQLite configuration store migrates itself to schema version 4 (tables `auth_sessions`, `auth_attempts`).

## 1.1.0 - 2026-10-28

A friendlier admin GUI with a PocketBase-like flow, a JavaScript SDK, UUID ids, rate limiting, JSON logs,
S3 / R2 style storage with orphan file cleanup, and the fixes of a security audit of the generated API.

### Added

- **JavaScript SDK** (`sdk/js`, also served by the API at `/api/sdk.js`): PocketBase style collections (`getList`,
  `getFullList`, `getFirstListItem`, `getOne`, `create`, `createMany`, `upsert`, `update`, `updateMany`, `delete`,
  `deleteMany`, `clone`, files, relation options, actions, schema), storage buckets (`list`, `listAll`, `info`,
  `upload`, `download`, `getUrl`, `remove`), RPC, an auth store for user JWTs, typed errors and TypeScript definitions.
  No dependencies; browsers and Node.js 18+.
- **UUID primary keys**: `Resource::setIdGenerator('uuid' | 'uuid7' | 'none')` (GUI: endpoint > General);
  columns of type `uuid` get a UUID v4 by default; ids sent by clients are validated.
- **Rate limiting** per API key, JWT user or IP: global settings (`rate_limit_enabled`, `rate_limit_requests`,
  `rate_limit_window`, `rate_limit_anon_requests`, `rate_limit_exempt_service`) and per resource
  (`Resource::setRateLimit()`); `429` with `Retry-After` and `X-RateLimit-*` headers; failed authentications count
  against the client IP. Counters in APCu when available, otherwise in SQLite.
- **JSON logs**: one `.jsonl` file per day (`logPath`, default `{storagePath}/logs`) of API requests and admin actions,
  with retention (`log_requests`, `log_admin`, `log_retention_days`). Request bodies are never logged and API keys
  in query strings are masked. `$api->logs()` reads, filters and deletes them.
- **Storage buckets organized like S3 / Cloudflare R2**: the key is the path on disk, so a bucket folder syncs 1:1 to
  S3 / R2; an object index in SQLite holds size, ETag (MD5), Content-Type, original name, `Cache-Control`, custom
  metadata and owner; atomic uploads through `buckets/.tmp`; ListObjectsV2 listing (`prefix`, `delimiter`, `limit`,
  `cursor`, binary key order); `ETag`, `Last-Modified`, `304` on `If-None-Match`, `HEAD` and `?info`; empty folders
  are removed; files copied into a bucket by hand are indexed.
- **Orphan file cleanup** (`$api->janitor()`): orphan field upload files, folders of deleted buckets, stale metadata,
  untracked files, interrupted uploads, empty folders and the trash. Dry run first; orphans are moved to
  `{storagePath}/trash/{day}` (`orphan_min_age_hours`, `storage_trash_days`); safe daily housekeeping
  (`storage_auto_cleanup`); `php spark grocery-restable:cleanup [--run]`.
- Admin GUI:
  - a section rail separating **Data** (browse and edit tables), **API** (endpoints, keys, docs, console),
    **Storage**, **Logs** and **Settings** (one page per topic), with a sidebar per section;
  - side drawers for rows and editors, a tabbed endpoint editor, bulk row delete, an unsaved changes warning and
    Ctrl+S;
  - code explorers with JavaScript SDK, cURL and PHP samples on the Overview, and a per-endpoint **API preview**
    (operations, parameters, body fields, sample responses);
  - a **Logs** area (counters, entries per hour, filters, live view, download, delete);
  - **My account** to change your username and password (current password required); other admins can be renamed
    or get a new password;
  - **Settings**: Rate limiting, Logging and Storage cleanup pages; JWT options;
  - storage: object metadata drawer, *Load more*, `Cache-Control` and metadata on upload, deleting a bucket can move
    its files to the trash;
  - a *UUID* button in the row editor for primary keys that are not auto increment.
- Settings `jwt_require_exp` and `jwt_allow_service_role`; bootstrap options `logPath` and `maxBodySize`.
- Documentation: JavaScript SDK, Rate limiting, Logging, Orphan files and cleanup, S3 style storage.
- The release workflow also runs the SDK tests.

### Changed

- The admin front end is split into small modules (`src/Admin/assets/app/`) with an Express-style hash router.
  Links of the previous layout (`#/resources`, `#/table/...`, `#/keys` ...) redirect to the new pages.
- `openapi.json` only describes the resources, buckets and RPC functions the caller can use (`service_role` sees
  everything).
- Rows deleted or changed in the admin Data browser delete the upload files they no longer use, like the API does.
- The title of an auto relation is never guessed from a column that looks secret (`password`, `token`, `hash`,
  `key` ...).
- No em dash in code, messages or documentation.

### Security

Fixes from an audit of the generated API:

- Row level security scopes are enforced on inserts and updates: a row cannot be created in, or moved into, another
  user's scope (`403`, rolled back).
- Scope conditions compared with a missing value (`where('owner_id', $auth->userId())` without `sub`) match no row
  instead of `IS NULL` rows.
- Relations cannot reach tables excluded from auto expose or whose GUI resource is disabled.
- Filters on relations and `/_options/{field}` require `select` on the related resource and use only its readable
  columns.
- `many_to_many` writes only accept ids the caller can read.
- JWTs cannot grant `service_role` (unless `jwt_allow_service_role`), must have `exp` (`jwt_require_exp`), numeric time
  claims and the configured algorithm.
- Upsert respects a disabled update operation.
- Query strings are limited to 16 KB and `select` / `or` / `and` to 10 nested levels (denial of service).
- Upload endpoints follow the field rules (writable to upload, readable to download).
- `X-HTTP-Method-Override` / `_method` only apply to form and multipart bodies.
- `NativeServer` refuses bodies above `maxBodySize` (`413`) and no longer URL-decodes paths twice.
- Private buckets never send a shared `Cache-Control` header.

### Fixed

- A slow page of the admin GUI could overwrite the page opened after it; navigations now run one after the other.
- A dialog opened while another one was closing never appeared.

### Upgrade notes

Run `composer update kctofficial/grocery-restable`. The SQLite store migrates itself (new tables `rate_limits` and
`storage_objects`); existing bucket files are indexed the first time a bucket is listed. Behaviour that changed
since 1.0.0:

- **Rate limiting is on by default** (600 requests per minute for keys and users, 120 per IP for anonymous callers,
  `service_role` exempt). Adjust or disable it in *Settings > Rate limiting*.
- **JWTs without `exp`**, signed with another HMAC algorithm than `jwt_algorithm`, or with `role: service_role` are
  rejected. Re-enable with `jwt_require_exp = false` / `jwt_allow_service_role = true` if you depend on them.
- **Storage listing follows S3**: `prefix` is a raw key prefix, list a folder with a trailing slash
  (`?prefix=users/42/`); the response adds `prefixes`, `truncated` and `next_cursor`. Uploading over an existing
  object (`upsert`) answers `200` instead of `201`.
- **JSON requests ignore `X-HTTP-Method-Override`**; send the real method (multipart and form requests keep it).
- **`/openapi.json` is filtered by role**: anonymous clients only see what they can use.
- The admin XHR action `user.password` is replaced by `user.update` (other admins) and `account.update` (yourself).

## 1.0.0 - 2026-10-26

Complete rewrite of the project as **Grocery Restable**: an instant REST API generator with a Grocery CRUD style API.
Inspired by Grocery CRUD. Not affiliated with or endorsed by Grocery CRUD / John Skoumbourdis.

- The forked Grocery CRUD (CodeIgniter 3) sources, its sample database and its license files have been removed; they
  remain available in the git history (last commit before the rewrite: `fcdde04`). The new code base is MIT licensed.

- Framework-agnostic core distributed as a Composer library (`kctofficial/grocery-restable`), PHP 8.2+.
- Integrations: **CodeIgniter 4** (auto-discovered routes, service, spark commands, database groups, validation,
  sessions) and **no framework** (`NativeServer`).
- Engines: MySQL / MariaDB (PDO or MySQLi), PostgreSQL (PDO), SQLite (PDO); CodeIgniter MySQLi / Postgre / SQLite3.
- PostgREST-compatible querying: projection, embedding, filters, logical groups, relation filters, ordering, paging,
  counts, search, CSV.
- Writes: create, bulk create, upsert, update, replace, bulk update, delete, bulk delete, clone, many-to-many sync,
  validation (CodeIgniter rules), unique fields, field types, callbacks, actions, RPC.
- Field uploads and storage buckets with strict file validation.
- API keys, JWT, per-role permissions, row-level security scopes, CORS.
- OpenAPI 3.1 document and `_schema` metadata.
- Schema changes are detected immediately with a cheap fingerprint query per request (no waiting for the cache TTL).
- Admin GUI flow: *Getting started* checklist, Resources page listing every table with one-click **Expose** and the
  auto expose switch, per-table status bar, notifications for new tables, highlight.js code blocks, favicon, and
  offline fallback copies of all CDN libraries.
- phpMyAdmin-style admin GUI (jQuery 4, Bootstrap 5.3, Font Awesome 7 via CDN) with setup wizard; configuration
  stored in SQLite.
