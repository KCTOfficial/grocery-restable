---
title: "Security"
description: "Production checklist, threat model and the protections built into Grocery Restable."
---

Grocery Restable exposes your database on the network, so its defaults are restrictive and every layer validates
its input. This page explains the protections and what you must configure.

## Production checklist

- [ ] `auth_mode` is **not** `none`.
- [ ] `default_permissions` give `anon` nothing (the default) and resources open only what is public.
- [ ] The `service_role` key is used **only** on servers; public clients use the `anon` key or user JWTs.
- [ ] `debug` is off (CodeIgniter: `CI_ENVIRONMENT = production`).
- [ ] `encryptionKey` is set (or the generated key file is backed up and readable only by PHP).
- [ ] `storagePath` (SQLite store, key file, uploads, buckets) is outside the web root and not world-readable.
- [ ] The admin GUI is disabled on public API servers, or restricted (`adminFilters`, VPN, reverse proxy allow-list).
- [ ] Setup was completed (the wizard is closed afterwards); `setupToken` removed if you used one.
- [ ] CORS origins are restricted to your web apps.
- [ ] HTTPS everywhere (API keys and JWTs are bearer credentials).
- [ ] The database user has only the privileges the API needs.
- [ ] Rate limits fit your traffic (Settings > Rate limiting); behind a reverse proxy the client IP is forwarded (CodeIgniter `proxyIPs`).
- [ ] Logs are retained as long as you need them, and `storagePath/logs` is backed up or shipped if you audit API use.
- [ ] After migrations, review which new tables became endpoints (auto expose exposes them immediately).

## SQL injection

- All values are bound as parameters (`?`), with PDO native prepares (`ATTR_EMULATE_PREPARES = false` for MySQL),
  MySQLi prepared statements or CodeIgniter bindings.
- Every identifier coming from a request (columns, relations, aliases, order) is validated against an allow-list
  built from the introspected schema and the resource definition, then quoted by the engine grammar.
- `where()` / GUI conditions accept a column name and a fixed operator set, never raw SQL. Raw SQL is only possible
  in code (relation `$where` strings, your own actions / RPC functions) where you control it.
- Filter values are type checked against the column type before reaching the database.

## Authorization

- Default deny for `anon`; per-role permissions per resource and bucket; `service_role` bypass only with its key.
- Row-level `scope()` conditions apply to reads, updates, deletes, actions, uploads, downloads and to rows embedded
  from that resource; rows outside the scope are indistinguishable from missing rows (`404`).
- Embedding a table requires `select` on its resource; tables that are not exposed only reveal their key and title
  columns.
- Columns typed `hidden`, `invisible` or `password` are never returned and can never be filtered, ordered or
  searched (no boolean oracle on password hashes).
- A table that has a code or GUI resource is never auto exposed again under its own name.
- Bulk update / delete require at least one filter and are capped at `maxBulkRows`.

## Authentication

- API keys: 192-bit random, stored as SHA-256 hashes, revocable, shown once.
- JWT: HMAC only (`alg: none` / RS*/ES* rejected), constant time signature comparison, `exp` / `nbf` / `iss` / `aud`.
- Invalid credentials return `401` instead of falling back to `anon`.

## Uploads

Extension allow-lists, mandatory rejection of server-executable extensions and double extensions, active content
(HTML/SVG/JS) only when explicitly allowed, MIME sniffing that must match the extension, random sanitized file names,
storage outside the web root, `realpath` containment checks, safe download headers (`nosniff`, sandbox CSP,
attachment for non-inline types). Upload columns cannot be set to arbitrary paths through JSON. Bucket uploads are
written atomically (temporary file, then rename); private buckets never send a shared cache header, whatever the
object metadata says. Orphan files are moved to a trash outside the web root before they are deleted. See
[Uploads & storage](uploads-and-storage.md).

## Output

- JSON encoded with invalid UTF-8 substitution; `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Referrer-Policy: no-referrer` on every API response.
- CSV export neutralizes spreadsheet formulas (`=`, `+`, `-`, `@` prefixes).
- Error messages never contain SQL, stack traces or database messages unless `debug` is on; 5xx errors are logged.

## Admin GUI

- Password hashing with `password_hash()` (rehash on login), timing-equalized login, 5 failures / 15 minutes / IP
  throttling, session id regeneration, 2-hour idle timeout.
- CSRF token required for the login form and every XHR call; XHR calls also require the session.
- Strict CSP (no inline scripts), CDN assets pinned with Subresource Integrity, every rendered value HTML-escaped,
  `X-Frame-Options: DENY`, `Cache-Control: no-store`.
- Secrets are write-only (database passwords and the JWT secret are never sent to the browser); exports exclude
  users, keys and secrets.
- The console runs requests server-side as a chosen role; it is available only to logged-in admins.

### Setup wizard exposure

Before the first setup the wizard is reachable only from `trustedSetupIps` (`127.0.0.1`, `::1`) or with
`?token=<setupToken>`. **Behind a reverse proxy that connects from localhost, every visitor looks local** - in that
case set `setupToken` (or run `php spark grocery-restable:setup`) before deploying, and make sure CodeIgniter's
`proxyIPs` is configured so the real client IP is used.

## Secrets at rest

Database passwords and the JWT secret are encrypted in SQLite with libsodium `secretbox` using `encryptionKey`
(derived to 256 bits with BLAKE2b). Without a configured key, a random key file is created next to the store with
`0600` permissions - anyone who can read both files can decrypt the secrets, so prefer an environment variable.

## Abuse protection

- **Rate limiting** per API key, JWT user or IP, globally and per resource; failed authentications count against the
  IP, which slows down key and token guessing. See [Rate limiting](rate-limiting.md).
- **Query complexity:** query strings are capped at 16 KB and `select`, `or`, `and` at 10 nested levels; embedding
  depth (`maxEmbedDepth`), page size (`max_limit`) and bulk rows (`maxBulkRows`) are bounded.
- **Request bodies** above `maxBodySize` (10 MB) are refused by `NativeServer` with `413`.
- **Method override** (`X-HTTP-Method-Override`, `_method`) is only honoured for multipart / form bodies, so proxies
  and firewalls see the real method of JSON requests.

## Row level security on writes

`scope()` conditions are checked on reads **and** writes: a create or update whose result would fall outside the
caller's scope (e.g. `POST {"owner_id": "someone else"}`) is rolled back with `403`. Scope conditions compared with a
missing value (`where('owner_id', $auth->userId())` without a `sub`) match no row instead of `IS NULL`.
`many_to_many` writes only accept ids the caller can read on the related resource.

## Information exposure

- Relations never reach tables listed in `auto_exclude_tables` or whose GUI resource is disabled (`403`), and the
  title of an auto relation is never guessed from a column that looks secret (`password`, `token`, `hash`, `key` ...).
- Filtering on a relation (`rel.column=...`) and `/_options/{field}` require `select` on the related resource and
  only use its readable columns.
- `/openapi.json` describes only the resources, buckets and functions the caller can use.
- Upload endpoints follow the field rules: `upload` needs the field writable on update, `file` needs it readable.

## JWT

Only the configured HMAC algorithm is accepted, `exp` is required (`jwt_require_exp`), time claims must be numbers,
and a token cannot grant `service_role` unless `jwt_allow_service_role` is on.

## User login

- Passwords are hashed with argon2id (or bcrypt / argon2i) in standard `password_hash()` formats and rehashed when the
  settings get stronger; legacy digests are verify-only and opt-in.
- Unknown users and wrong passwords answer the same `401` in the same time; failures are throttled per account and
  per IP.
- Refresh tokens and session tokens are 256-bit random values stored as SHA-256 hashes; refresh tokens are single use
  with reuse detection. Answers carry `Cache-Control: no-store`.
- Google ID tokens are verified locally (signature, issuer, audience, expiry, optional Workspace domain); accounts are
  linked by email only when Google reports it verified. See the caution in
  [Authentication](authentication.md#google-sign-in) if your app registers users without verifying emails.
- Request bodies (passwords, tokens) are never logged.

## Logs

API requests and admin actions are written as JSON lines, one file per day (see [Logging](logging.md)). Request
bodies are never logged, API keys in query strings are masked, and the files are created with `0600` permissions.

## Reporting vulnerabilities

Please report security issues privately to the maintainers instead of opening a public issue.
