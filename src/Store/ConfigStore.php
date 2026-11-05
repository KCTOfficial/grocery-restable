<?php

declare(strict_types=1);

namespace GroceryRestable\Store;

use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Support\Crypto;
use PDO;

/**
 * SQLite backed store for everything configured from the admin GUI:
 * settings, database connections, resources, API keys, storage buckets,
 * admin users, login throttling and the schema cache.
 */
final class ConfigStore
{
    private const SCHEMA_VERSION = 4;

    /**
     * Runtime settings and their defaults.
     */
    public const SETTING_DEFAULTS = [
        'setup_completed' => false,
        'framework' => null,
        'app_name' => 'Grocery Restable',
        'api_description' => '',
        'default_connection' => 'default',
        // Expose every table of the default connection that is not configured explicitly.
        'auto_expose' => false,
        'auto_exclude_tables' => ['migrations'],
        // none | api_key | jwt | any
        'auth_mode' => 'api_key',
        // Permissions used by resources that do not define their own.
        'default_permissions' => [
            'anon' => [],
            'authenticated' => ['select', 'insert', 'update', 'delete'],
        ],
        'jwt_secret' => '',
        'jwt_algorithm' => 'HS256',
        'jwt_role_claim' => 'role',
        'jwt_default_role' => 'authenticated',
        'jwt_audience' => '',
        'jwt_issuer' => '',
        // Reject tokens without "exp" (they would never expire).
        'jwt_require_exp' => true,
        // service_role bypasses every permission; by default only its API key grants it.
        'jwt_allow_service_role' => false,
        // Accept ?apikey=... (handy for <img src>, but keys end up in access logs).
        'allow_query_apikey' => false,
        'cors_enabled' => true,
        'cors_origins' => ['*'],
        'cors_max_age' => 600,
        'default_per_page' => 25,
        'max_limit' => 1000,
        // Wrap list responses in {"data": [...], "meta": {...}} instead of a bare array.
        'response_envelope' => false,
        'schema_cache_ttl' => 300,
        'upload_allowed_types' => 'gif|jpeg|jpg|png|webp|tiff|doc|docx|txt|odt|xls|xlsx|pdf|ppt|pptx|csv|zip|mp3|m4a|ogg|wav|mp4|m4v|mov|webm',
        'upload_max_size' => '20MB',
        'delete_replaced_files' => true,
        'expose_openapi' => true,
        // Rate limiting of the REST API (fixed window, per client).
        'rate_limit_enabled' => true,
        'rate_limit_requests' => 600,
        'rate_limit_window' => 60,
        // Anonymous callers (role anon); 0 = same limit as everybody.
        'rate_limit_anon_requests' => 120,
        'rate_limit_exempt_service' => true,
        // JSON logs in {logPath}/YYYY-MM-DD.jsonl. log_requests: all | errors | none.
        'log_requests' => 'all',
        'log_admin' => true,
        'log_retention_days' => 14,
        // Storage housekeeping: once a day remove temporary files, empty folders, stale index rows
        // and expired trash, and index files copied into buckets by hand. Orphans are never removed automatically.
        'storage_auto_cleanup' => true,
        // Files younger than this are never treated as orphans (uploads still in progress).
        'orphan_min_age_hours' => 24,
        // Cleaned files are moved to {storagePath}/trash and deleted after this many days (0 = delete immediately).
        'storage_trash_days' => 7,

        // User login (/auth): users live in a table of your own database.
        'auth_password_enabled' => false,
        'auth_google_enabled' => false,
        // '' = the default connection.
        'auth_users_connection' => '',
        'auth_users_table' => 'users',
        'auth_users_id_column' => 'id',
        // The login "identity" is looked up in these columns, in this order.
        'auth_users_identity_columns' => ['email'],
        'auth_users_password_column' => 'password',
        // Optional: role of the user ('' = jwt_default_role), "active" flag (falsy = login refused).
        'auth_users_role_column' => '',
        'auth_users_active_column' => '',
        // Columns copied into the token claims (e.g. tenant_id for scope()).
        'auth_users_claim_columns' => [],
        // Columns never returned by /auth (the password column never is).
        'auth_users_hidden_columns' => [],
        // jwt: short lived JWT + rotating refresh token | session: opaque, revocable server side session token.
        'auth_token_strategy' => 'jwt',
        'auth_access_ttl' => 900,
        // Lifetime of a refresh token / session.
        'auth_session_ttl' => 2592000,
        // Password hashing (new hashes and rehash on login).
        'auth_password_algorithm' => 'argon2id',
        'auth_password_cost' => 12,
        'auth_password_memory' => 65536,
        'auth_password_time' => 4,
        // Verify-only legacy digests (md5, sha1, sha256, sha512), upgraded on the next successful login.
        'auth_password_legacy' => [],
        'auth_password_rehash' => true,
        // Brute force protection: failed logins per account / per IP within the window (seconds).
        'auth_max_attempts' => 5,
        'auth_max_attempts_ip' => 20,
        'auth_attempts_window' => 900,
        // Google sign-in: OAuth client ids accepted as audience (web, Android, iOS).
        'auth_google_client_ids' => [],
        // Only needed to exchange authorization codes.
        'auth_google_client_secret' => '',
        'auth_google_id_column' => 'google_id',
        'auth_google_email_column' => 'email',
        // Link a Google account to an existing user with the same (verified) email.
        'auth_google_link_by_email' => true,
        // Create a user on the first Google sign-in.
        'auth_google_signup' => false,
        // column => Google claim for new users, e.g. {"name": "name", "avatar": "picture"}
        'auth_google_signup_fields' => [],
        // Restrict to Google Workspace domains (hd claim). Empty = any account.
        'auth_google_hosted_domains' => [],
    ];

    /**
     * Settings that are encrypted at rest and never returned to the GUI in clear text.
     */
    public const SECRET_SETTINGS = ['jwt_secret', 'auth_google_client_secret'];

    private ?PDO $pdo = null;

    /** @var array<string, mixed>|null */
    private ?array $settingsCache = null;

    public function __construct(
        private readonly string $path,
        private readonly Crypto $crypto,
    ) {
    }

    public function crypto(): Crypto
    {
        return $this->crypto;
    }

    public function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        if ($this->path !== ':memory:') {
            $dir = dirname($this->path);
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new ConfigurationException('Unable to create the Grocery Restable storage directory: ' . $dir);
            }
        }

        $isNew = $this->path === ':memory:' || ! is_file($this->path);
        $this->pdo = new PDO('sqlite:' . $this->path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        if ($isNew && $this->path !== ':memory:') {
            @chmod($this->path, 0600);
        }
        $this->migrate();

        return $this->pdo;
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        if ($this->settingsCache === null) {
            $values = self::SETTING_DEFAULTS;
            foreach ($this->pdo()->query('SELECT key, value FROM settings') as $row) {
                $value = json_decode($row['value'], true);
                if (in_array($row['key'], self::SECRET_SETTINGS, true) && is_string($value)) {
                    $value = $this->crypto->decrypt($value);
                }
                $values[$row['key']] = $value;
            }
            $this->settingsCache = $values;
        }

        return $this->settingsCache;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings()[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function saveSettings(array $values): void
    {
        $statement = $this->pdo()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::SETTING_DEFAULTS)) {
                throw new ConfigurationException('Unknown setting "' . $key . '".');
            }
            if (in_array($key, self::SECRET_SETTINGS, true) && is_string($value)) {
                $value = $this->crypto->encrypt($value);
            }
            $statement->execute([$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]);
        }
        $this->settingsCache = null;
    }

    public function isSetupCompleted(): bool
    {
        return (bool) $this->setting('setup_completed', false);
    }

    // ------------------------------------------------------------------
    // Database connections
    // ------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>> Connection definitions keyed by name (passwords decrypted).
     */
    public function connections(): array
    {
        $result = [];
        foreach ($this->pdo()->query('SELECT name, config FROM connections ORDER BY name') as $row) {
            $config = json_decode($row['config'], true) ?: [];
            $config['password'] = $this->crypto->decrypt($config['password'] ?? null);
            $result[$row['name']] = $config + ['name' => $row['name']];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function connection(string $name): ?array
    {
        return $this->connections()[$name] ?? null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function saveConnection(string $name, array $config): void
    {
        self::assertName($name, 'connection');
        unset($config['name']);
        $config['password'] = $this->crypto->encrypt(isset($config['password']) ? (string) $config['password'] : null);

        $now = gmdate('c');
        $this->pdo()->prepare(
            'INSERT INTO connections (name, config, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(name) DO UPDATE SET config = excluded.config, updated_at = excluded.updated_at',
        )->execute([$name, json_encode($config, JSON_THROW_ON_ERROR), $now, $now]);
        $this->clearCache('schema:' . $name);
    }

    public function deleteConnection(string $name): void
    {
        $this->pdo()->prepare('DELETE FROM connections WHERE name = ?')->execute([$name]);
        $this->clearCache('schema:' . $name);
    }

    // ------------------------------------------------------------------
    // Resources (GUI defined)
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{definition: array<string, mixed>, enabled: bool, updated_at: string|null}>
     */
    public function resources(): array
    {
        $result = [];
        foreach ($this->pdo()->query('SELECT name, definition, enabled, updated_at FROM resources ORDER BY name') as $row) {
            $result[$row['name']] = [
                'definition' => json_decode($row['definition'], true) ?: [],
                'enabled' => (bool) $row['enabled'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function saveResource(string $name, array $definition, bool $enabled = true): void
    {
        self::assertName($name, 'resource');
        $now = gmdate('c');
        $this->pdo()->prepare(
            'INSERT INTO resources (name, definition, enabled, created_at, updated_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(name) DO UPDATE SET definition = excluded.definition, enabled = excluded.enabled, updated_at = excluded.updated_at',
        )->execute([$name, json_encode($definition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), (int) $enabled, $now, $now]);
    }

    public function deleteResource(string $name): void
    {
        $this->pdo()->prepare('DELETE FROM resources WHERE name = ?')->execute([$name]);
    }

    // ------------------------------------------------------------------
    // API keys
    // ------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    public function apiKeys(): array
    {
        return $this->pdo()->query('SELECT id, name, prefix, role, created_at, last_used_at, revoked_at FROM api_keys ORDER BY id')->fetchAll();
    }

    /**
     * Create a key and return the plain value. Only its SHA-256 hash is stored.
     */
    public function createApiKey(string $name, string $role): string
    {
        self::assertRole($role);
        $plain = 'gr_' . bin2hex(random_bytes(24));
        $this->pdo()->prepare('INSERT INTO api_keys (name, prefix, key_hash, role, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([trim($name) !== '' ? trim($name) : 'API key', substr($plain, 0, 11), hash('sha256', $plain), $role, gmdate('c')]);

        return $plain;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findApiKey(string $plain): ?array
    {
        $statement = $this->pdo()->prepare('SELECT id, name, role, last_used_at FROM api_keys WHERE key_hash = ? AND revoked_at IS NULL');
        $statement->execute([hash('sha256', $plain)]);
        $row = $statement->fetch();
        if (! $row) {
            return null;
        }

        // Avoid a write on every request: refresh "last used" at most once a minute.
        if ($row['last_used_at'] === null || strtotime($row['last_used_at']) < time() - 60) {
            $this->pdo()->prepare('UPDATE api_keys SET last_used_at = ? WHERE id = ?')->execute([gmdate('c'), $row['id']]);
        }

        return $row;
    }

    public function revokeApiKey(int $id): void
    {
        $this->pdo()->prepare('UPDATE api_keys SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([gmdate('c'), $id]);
    }

    public function deleteApiKey(int $id): void
    {
        $this->pdo()->prepare('DELETE FROM api_keys WHERE id = ?')->execute([$id]);
    }

    // ------------------------------------------------------------------
    // Storage buckets
    // ------------------------------------------------------------------

    /**
     * @return array<string, array<string, mixed>>
     */
    public function buckets(): array
    {
        $result = [];
        foreach ($this->pdo()->query('SELECT name, definition FROM buckets ORDER BY name') as $row) {
            $result[$row['name']] = (json_decode($row['definition'], true) ?: []) + ['name' => $row['name']];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function saveBucket(string $name, array $definition): void
    {
        self::assertName($name, 'bucket');
        unset($definition['name']);
        $now = gmdate('c');
        $this->pdo()->prepare(
            'INSERT INTO buckets (name, definition, created_at, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(name) DO UPDATE SET definition = excluded.definition, updated_at = excluded.updated_at',
        )->execute([$name, json_encode($definition, JSON_THROW_ON_ERROR), $now, $now]);
    }

    public function deleteBucket(string $name): void
    {
        $this->pdo()->prepare('DELETE FROM buckets WHERE name = ?')->execute([$name]);
    }

    // ------------------------------------------------------------------
    // Admin users
    // ------------------------------------------------------------------

    public function countUsers(): int
    {
        return (int) $this->pdo()->query('SELECT COUNT(*) FROM admin_users')->fetchColumn();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function users(): array
    {
        return $this->pdo()->query('SELECT id, username, created_at, last_login_at FROM admin_users ORDER BY username')->fetchAll();
    }

    public function createUser(string $username, string $password): int
    {
        $username = trim($username);
        self::assertUsername($username);
        self::assertPassword($password);

        $this->pdo()->prepare('INSERT INTO admin_users (username, password_hash, created_at) VALUES (?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), gmdate('c')]);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Verify credentials. Returns the user row on success.
     *
     * @return array<string, mixed>|null
     */
    public function verifyUser(string $username, string $password): ?array
    {
        $statement = $this->pdo()->prepare('SELECT id, username, password_hash FROM admin_users WHERE username = ?');
        $statement->execute([trim($username)]);
        $user = $statement->fetch();

        if (! $user) {
            // Equalize timing between unknown users and wrong passwords.
            password_verify($password, password_hash('grocery-restable-timing-guard', PASSWORD_DEFAULT));

            return null;
        }
        if (! password_verify($password, $user['password_hash'])) {
            return null;
        }

        $updates = ['last_login_at = ?' => gmdate('c')];
        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $updates['password_hash = ?'] = password_hash($password, PASSWORD_DEFAULT);
        }
        $this->pdo()->prepare('UPDATE admin_users SET ' . implode(', ', array_keys($updates)) . ' WHERE id = ?')
            ->execute([...array_values($updates), $user['id']]);

        unset($user['password_hash']);

        return $user;
    }

    public function changePassword(int $id, string $password): void
    {
        self::assertPassword($password);
        $this->pdo()->prepare('UPDATE admin_users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
    }

    public function changeUsername(int $id, string $username): void
    {
        $username = trim($username);
        self::assertUsername($username);
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM admin_users WHERE username = ? AND id <> ?');
        $statement->execute([$username, $id]);
        if ((int) $statement->fetchColumn() > 0) {
            throw new ConfigurationException('The username "' . $username . '" is already taken.');
        }
        $this->pdo()->prepare('UPDATE admin_users SET username = ? WHERE id = ?')->execute([$username, $id]);
    }

    /**
     * Check the password of an admin user (without touching last_login_at).
     */
    public function checkPassword(int $id, string $password): bool
    {
        $statement = $this->pdo()->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $statement->execute([$id]);
        $hash = $statement->fetchColumn();

        return is_string($hash) && password_verify($password, $hash);
    }

    public function deleteUser(int $id): void
    {
        if ($this->countUsers() <= 1) {
            throw new ConfigurationException('The last admin user cannot be deleted.');
        }
        $this->pdo()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$id]);
    }

    // ------------------------------------------------------------------
    // Login throttling
    // ------------------------------------------------------------------

    public function recordFailedLogin(string $ip, string $username): void
    {
        $this->pdo()->prepare('INSERT INTO login_attempts (ip, username, attempted_at) VALUES (?, ?, ?)')
            ->execute([$ip, mb_substr($username, 0, 64), time()]);
        $this->pdo()->prepare('DELETE FROM login_attempts WHERE attempted_at < ?')->execute([time() - 86400]);
    }

    public function recentFailedLogins(string $ip, int $windowSeconds): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= ?');
        $statement->execute([$ip, time() - $windowSeconds]);

        return (int) $statement->fetchColumn();
    }

    public function clearFailedLogins(string $ip): void
    {
        $this->pdo()->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    }

    // ------------------------------------------------------------------
    // User sessions (/auth): opaque session tokens and JWT refresh tokens
    // ------------------------------------------------------------------

    /**
     * Start a session. Only the SHA-256 hash of the token is stored.
     *
     * @param string $kind "session" (the token is the bearer token) or "refresh" (the token renews JWTs)
     * @param array<string, mixed> $claims
     * @return array{id: string, token: string, expires_at: int}
     */
    public function createAuthSession(string $kind, string $userId, string $role, array $claims, string $provider, int $ttl, string $ip = '', string $userAgent = ''): array
    {
        $id = bin2hex(random_bytes(16));
        $token = self::authToken($kind);
        $now = time();
        $expires = $now + max(60, $ttl);
        $this->pdo()->prepare(
            'INSERT INTO auth_sessions (id, kind, token_hash, user_id, role, claims, provider, ip, user_agent, created_at, last_used_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        )->execute([$id, $kind, hash('sha256', $token), $userId, $role, json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $provider, mb_substr($ip, 0, 64), mb_substr($userAgent, 0, 200), $now, $now, $expires]);
        if (random_int(1, 50) === 1) {
            $this->pdo()->prepare('DELETE FROM auth_sessions WHERE expires_at < ? OR revoked_at < ?')->execute([$now - 86400, $now - 86400]);
        }

        return ['id' => $id, 'token' => $token, 'expires_at' => $expires];
    }

    /**
     * Active session of a token of the given kind (claims decoded), or null.
     *
     * @return array<string, mixed>|null
     */
    public function findAuthSession(string $token, string $kind = 'session'): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM auth_sessions WHERE token_hash = ? AND kind = ? AND revoked_at IS NULL AND expires_at > ?');
        $statement->execute([hash('sha256', $token), $kind, time()]);
        $row = $statement->fetch();
        if (! $row) {
            return null;
        }
        if ($row['last_used_at'] === null || (int) $row['last_used_at'] < time() - 60) {
            $this->pdo()->prepare('UPDATE auth_sessions SET last_used_at = ? WHERE id = ?')->execute([time(), $row['id']]);
        }

        return self::sessionRow($row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function authSession(string $id): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM auth_sessions WHERE id = ?');
        $statement->execute([$id]);
        $row = $statement->fetch();

        return $row ? self::sessionRow($row) : null;
    }

    /**
     * Rotate a refresh token: the presented token is replaced by a new one.
     *
     * Presenting an already rotated token within $leeway seconds (two tabs refreshing at once) yields another
     * new token; later it is treated as stolen and the whole session is revoked (refresh token reuse detection).
     *
     * @return array{session: array<string, mixed>, token: string}|null
     */
    public function rotateAuthSession(string $token, int $leeway = 10): ?array
    {
        $hash = hash('sha256', $token);
        $now = time();
        $pdo = $this->pdo();
        $find = $pdo->prepare('SELECT * FROM auth_sessions WHERE kind = ? AND (token_hash = ? OR previous_hash = ?)');
        $find->execute(['refresh', $hash, $hash]);
        $row = $find->fetch();
        if (! $row || $row['revoked_at'] !== null || (int) $row['expires_at'] <= $now) {
            return null;
        }

        $new = self::authToken('refresh');
        if (hash_equals($row['token_hash'], $hash)) {
            $update = $pdo->prepare('UPDATE auth_sessions SET previous_hash = token_hash, token_hash = ?, rotated_at = ?, last_used_at = ? WHERE id = ? AND token_hash = ?');
            $update->execute([hash('sha256', $new), $now, $now, $row['id'], $hash]);
            if ($update->rowCount() === 1) {
                return ['session' => self::sessionRow($row), 'token' => $new];
            }
            // Another request rotated it in the meantime: apply the "previous token" rules to the fresh row.
            $find->execute(['refresh', $hash, $hash]);
            $row = $find->fetch();
            if (! $row || $row['revoked_at'] !== null) {
                return null;
            }
        }

        if ($row['rotated_at'] !== null && $now - (int) $row['rotated_at'] <= $leeway) {
            $pdo->prepare('UPDATE auth_sessions SET token_hash = ?, rotated_at = ?, last_used_at = ? WHERE id = ?')
                ->execute([hash('sha256', $new), $now, $now, $row['id']]);

            return ['session' => self::sessionRow($row), 'token' => $new];
        }

        $this->revokeAuthSession((string) $row['id']);

        return null;
    }

    /**
     * Refresh the role and claims of a session (after the user row was read again).
     *
     * @param array<string, mixed> $claims
     */
    public function updateAuthSession(string $id, string $role, array $claims): void
    {
        $this->pdo()->prepare('UPDATE auth_sessions SET role = ?, claims = ? WHERE id = ?')
            ->execute([$role, json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id]);
    }

    public function revokeAuthSession(string $id): void
    {
        $this->pdo()->prepare('UPDATE auth_sessions SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL')->execute([time(), $id]);
    }

    /**
     * Sign a user out everywhere (e.g. after a password change). Returns the number of revoked sessions.
     */
    public function revokeUserAuthSessions(string $userId): int
    {
        $statement = $this->pdo()->prepare('UPDATE auth_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL');
        $statement->execute([time(), $userId]);

        return $statement->rowCount();
    }

    /**
     * Active sessions, newest first (without token hashes).
     *
     * @return list<array<string, mixed>>
     */
    public function authSessions(?string $userId = null, int $limit = 200): array
    {
        $sql = 'SELECT id, kind, user_id, role, provider, ip, user_agent, created_at, last_used_at, expires_at FROM auth_sessions
                WHERE revoked_at IS NULL AND expires_at > ?';
        $bindings = [time()];
        if ($userId !== null) {
            $sql .= ' AND user_id = ?';
            $bindings[] = $userId;
        }
        $statement = $this->pdo()->prepare($sql . ' ORDER BY created_at DESC LIMIT ' . max(1, $limit));
        $statement->execute($bindings);

        return $statement->fetchAll();
    }

    /**
     * Count a failed login for each key ("ip:..." and "id:<hash>").
     *
     * @param list<string> $keys
     */
    public function recordAuthFailure(array $keys): void
    {
        $now = time();
        $insert = $this->pdo()->prepare('INSERT INTO auth_attempts (key, attempted_at) VALUES (?, ?)');
        foreach ($keys as $key) {
            $insert->execute([$key, $now]);
        }
        if (random_int(1, 50) === 1) {
            $this->pdo()->prepare('DELETE FROM auth_attempts WHERE attempted_at < ?')->execute([$now - 86400]);
        }
    }

    /**
     * Failed logins of $key within the window and the seconds until the oldest one leaves it.
     *
     * @return array{count: int, retry_after: int}
     */
    public function authFailures(string $key, int $window): array
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) AS n, MIN(attempted_at) AS oldest FROM auth_attempts WHERE key = ? AND attempted_at > ?');
        $statement->execute([$key, time() - $window]);
        $row = $statement->fetch() ?: ['n' => 0, 'oldest' => null];

        return ['count' => (int) $row['n'], 'retry_after' => $row['oldest'] !== null ? max(1, (int) $row['oldest'] + $window - time()) : 0];
    }

    public function clearAuthFailures(string $key): void
    {
        $this->pdo()->prepare('DELETE FROM auth_attempts WHERE key = ?')->execute([$key]);
    }

    private static function authToken(string $kind): string
    {
        return ($kind === 'refresh' ? 'grr_' : 'grs_') . bin2hex(random_bytes(32));
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function sessionRow(array $row): array
    {
        unset($row['token_hash'], $row['previous_hash']);
        $row['claims'] = json_decode((string) $row['claims'], true) ?: [];

        return $row;
    }

    // ------------------------------------------------------------------
    // Storage object index (S3 style metadata of bucket files)
    // ------------------------------------------------------------------

    /**
     * Insert or replace the metadata of an object (created_at is kept on replace).
     *
     * @param array{size: int, etag: string, content_type: string, original_name?: string|null, cache_control?: string|null,
     *              metadata?: array<string, string>|null, owner?: string|null, created_at?: string, updated_at?: string} $object
     */
    public function putObject(string $bucket, string $path, array $object): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->pdo()->prepare(
            'INSERT INTO storage_objects (bucket, path, size, etag, content_type, original_name, cache_control, metadata, owner, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(bucket, path) DO UPDATE SET size = excluded.size, etag = excluded.etag, content_type = excluded.content_type,
                original_name = excluded.original_name, cache_control = excluded.cache_control, metadata = excluded.metadata,
                owner = excluded.owner, updated_at = excluded.updated_at',
        )->execute([
            $bucket, $path, $object['size'], $object['etag'], $object['content_type'], $object['original_name'] ?? null,
            $object['cache_control'] ?? null, isset($object['metadata']) && $object['metadata'] !== [] ? json_encode($object['metadata'], JSON_UNESCAPED_UNICODE) : null,
            $object['owner'] ?? null, $object['created_at'] ?? $now, $object['updated_at'] ?? $now,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function object(string $bucket, string $path): ?array
    {
        $statement = $this->pdo()->prepare('SELECT * FROM storage_objects WHERE bucket = ? AND path = ?');
        $statement->execute([$bucket, $path]);
        $row = $statement->fetch();

        return $row ? self::objectRow($row) : null;
    }

    public function deleteObject(string $bucket, string $path): void
    {
        $this->pdo()->prepare('DELETE FROM storage_objects WHERE bucket = ? AND path = ?')->execute([$bucket, $path]);
    }

    /**
     * Objects of a bucket in key order (binary, like S3), with a key prefix, after a key.
     *
     * @return list<array<string, mixed>>
     */
    public function objects(string $bucket, string $prefix = '', string $after = '', int $limit = 1000): array
    {
        $sql = 'SELECT * FROM storage_objects WHERE bucket = ? AND path > ?';
        $bindings = [$bucket, $after];
        if ($prefix !== '') {
            // A range instead of LIKE: no escaping of % and _, and the primary key index is used.
            $sql .= ' AND path >= ? AND path < ?';
            array_push($bindings, $prefix, $prefix . "\u{10FFFF}");
        }
        $statement = $this->pdo()->prepare($sql . ' ORDER BY path LIMIT ' . max(1, $limit));
        $statement->execute($bindings);

        return array_map([self::class, 'objectRow'], $statement->fetchAll());
    }

    public function countObjects(string $bucket): int
    {
        $statement = $this->pdo()->prepare('SELECT COUNT(*) FROM storage_objects WHERE bucket = ?');
        $statement->execute([$bucket]);

        return (int) $statement->fetchColumn();
    }

    /**
     * @return list<string> Buckets that have index rows.
     */
    public function indexedBuckets(): array
    {
        return $this->pdo()->query('SELECT DISTINCT bucket FROM storage_objects ORDER BY bucket')->fetchAll(PDO::FETCH_COLUMN);
    }

    public function deleteBucketObjects(string $bucket): int
    {
        $statement = $this->pdo()->prepare('DELETE FROM storage_objects WHERE bucket = ?');
        $statement->execute([$bucket]);

        return $statement->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function objectRow(array $row): array
    {
        $row['size'] = (int) $row['size'];
        $row['metadata'] = $row['metadata'] !== null ? (json_decode((string) $row['metadata'], true) ?: []) : [];

        return $row;
    }

    // ------------------------------------------------------------------
    // API rate limiting (fallback when APCu is not available)
    // ------------------------------------------------------------------

    /**
     * Count a hit for $key in the window starting at $windowStart; returns the hits of that window.
     */
    public function hitRateLimit(string $key, int $windowStart): int
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO rate_limits (key, window_start, hits) VALUES (?, ?, 1)
                 ON CONFLICT(key) DO UPDATE SET
                    hits = CASE WHEN rate_limits.window_start = excluded.window_start THEN rate_limits.hits + 1 ELSE 1 END,
                    window_start = excluded.window_start',
            )->execute([$key, $windowStart]);
            $statement = $pdo->prepare('SELECT hits FROM rate_limits WHERE key = ?');
            $statement->execute([$key]);
            $hits = (int) $statement->fetchColumn();
            if (random_int(1, 200) === 1) {
                $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([time() - 86400]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $hits;
    }

    // ------------------------------------------------------------------
    // Cache
    // ------------------------------------------------------------------

    public function cacheGet(string $key): mixed
    {
        $statement = $this->pdo()->prepare('SELECT value FROM cache WHERE key = ? AND expires_at > ?');
        $statement->execute([$key, time()]);
        $value = $statement->fetchColumn();

        return $value === false ? null : json_decode($value, true);
    }

    public function cachePut(string $key, mixed $value, int $ttl): void
    {
        if ($ttl <= 0) {
            return;
        }
        $this->pdo()->prepare(
            'INSERT INTO cache (key, value, expires_at) VALUES (?, ?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at',
        )->execute([$key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), time() + $ttl]);
    }

    public function clearCache(?string $key = null): void
    {
        if ($key === null) {
            $this->pdo()->exec('DELETE FROM cache');

            return;
        }
        $this->pdo()->prepare('DELETE FROM cache WHERE key = ?')->execute([$key]);
    }

    // ------------------------------------------------------------------
    // Import / export
    // ------------------------------------------------------------------

    /**
     * Export the GUI configuration (without users, keys and secrets) as an array.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $settings = $this->settings();
        foreach (self::SECRET_SETTINGS as $secret) {
            unset($settings[$secret]);
        }
        $connections = $this->connections();
        foreach ($connections as &$connection) {
            unset($connection['password']);
        }

        return [
            'version' => self::SCHEMA_VERSION,
            'exported_at' => gmdate('c'),
            'settings' => $settings,
            'connections' => $connections,
            'resources' => $this->resources(),
            'buckets' => $this->buckets(),
        ];
    }

    // ------------------------------------------------------------------

    private function migrate(): void
    {
        $version = (int) $this->pdo->query('PRAGMA user_version')->fetchColumn();
        if ($version >= self::SCHEMA_VERSION) {
            return;
        }

        $this->pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
            CREATE TABLE IF NOT EXISTS connections (name TEXT PRIMARY KEY, config TEXT NOT NULL, created_at TEXT, updated_at TEXT);
            CREATE TABLE IF NOT EXISTS resources (name TEXT PRIMARY KEY, definition TEXT NOT NULL, enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT);
            CREATE TABLE IF NOT EXISTS api_keys (
                id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, prefix TEXT NOT NULL, key_hash TEXT NOT NULL UNIQUE,
                role TEXT NOT NULL, created_at TEXT, last_used_at TEXT, revoked_at TEXT
            );
            CREATE TABLE IF NOT EXISTS buckets (name TEXT PRIMARY KEY, definition TEXT NOT NULL, created_at TEXT, updated_at TEXT);
            CREATE TABLE IF NOT EXISTS admin_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT, username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                password_hash TEXT NOT NULL, created_at TEXT, last_login_at TEXT
            );
            CREATE TABLE IF NOT EXISTS login_attempts (id INTEGER PRIMARY KEY AUTOINCREMENT, ip TEXT NOT NULL, username TEXT, attempted_at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS login_attempts_ip ON login_attempts (ip, attempted_at);
            CREATE TABLE IF NOT EXISTS cache (key TEXT PRIMARY KEY, value TEXT NOT NULL, expires_at INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY, window_start INTEGER NOT NULL, hits INTEGER NOT NULL);
            CREATE TABLE IF NOT EXISTS storage_objects (
                bucket TEXT NOT NULL, path TEXT NOT NULL, size INTEGER NOT NULL, etag TEXT NOT NULL, content_type TEXT NOT NULL,
                original_name TEXT, cache_control TEXT, metadata TEXT, owner TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
                PRIMARY KEY (bucket, path)
            );
            CREATE TABLE IF NOT EXISTS auth_sessions (
                id TEXT PRIMARY KEY, kind TEXT NOT NULL, token_hash TEXT NOT NULL UNIQUE, previous_hash TEXT,
                user_id TEXT NOT NULL, role TEXT NOT NULL, claims TEXT NOT NULL, provider TEXT NOT NULL, ip TEXT, user_agent TEXT,
                created_at INTEGER NOT NULL, rotated_at INTEGER, last_used_at INTEGER, expires_at INTEGER NOT NULL, revoked_at INTEGER
            );
            CREATE INDEX IF NOT EXISTS auth_sessions_user ON auth_sessions (user_id);
            CREATE INDEX IF NOT EXISTS auth_sessions_previous ON auth_sessions (previous_hash);
            CREATE TABLE IF NOT EXISTS auth_attempts (key TEXT NOT NULL, attempted_at INTEGER NOT NULL);
            CREATE INDEX IF NOT EXISTS auth_attempts_key ON auth_attempts (key, attempted_at);
            SQL);
        $this->pdo->exec('PRAGMA user_version = ' . self::SCHEMA_VERSION);
    }

    public static function assertName(string $name, string $what): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $name)) {
            throw new ConfigurationException(sprintf('Invalid %s name "%s". Use letters, digits, "_" or "-" (max 64, must not start with a digit).', $what, $name));
        }
    }

    public static function assertRole(string $role): void
    {
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $role)) {
            throw new ConfigurationException('Invalid role name "' . $role . '".');
        }
    }

    private static function assertUsername(string $username): void
    {
        if (! preg_match('/^[A-Za-z0-9._@-]{3,64}$/', $username)) {
            throw new ConfigurationException('Usernames must be 3-64 characters (letters, digits, . _ @ -).');
        }
    }

    private static function assertPassword(string $password): void
    {
        if (strlen($password) < 8) {
            throw new ConfigurationException('Passwords must be at least 8 characters long.');
        }
    }
}
