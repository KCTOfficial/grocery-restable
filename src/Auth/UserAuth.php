<?php

declare(strict_types=1);

namespace GroceryRestable\Auth;

use GroceryRestable\Database\ConnectionInterface;
use GroceryRestable\Database\Grammar\Grammar;
use GroceryRestable\Database\Schema\Table;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Exception\ValidationException;
use GroceryRestable\GroceryRestable;
use GroceryRestable\Http\Request;
use GroceryRestable\Service\RowTransformer;
use GroceryRestable\Support\HttpClient;
use GroceryRestable\Support\Jwt;

/**
 * User login for the REST API (/auth): password login against a table of your database and Google sign-in.
 *
 * Everything is configured in the admin GUI (Settings > User login); code can customize the flow:
 *
 *   $api->auth()
 *       ->claims(fn (array $user) => ['tenant_id' => (int) $user['tenant_id']])
 *       ->beforeLogin(function (array $user, string $provider) {
 *           if ($user['email_verified_at'] === null) {
 *               throw new ApiException('Verify your email first.', 403, 'email_not_verified');
 *           }
 *       });
 *
 * Tokens: "jwt" strategy = short lived JWT (verified like any other JWT) + rotating refresh token;
 * "session" strategy = one opaque, revocable bearer token. Both are tracked in the auth_sessions table.
 */
final class UserAuth
{
    public const STRATEGIES = ['jwt', 'session'];
    public const PROVIDERS = ['password', 'google'];

    private const ROLE_PATTERN = '/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/';
    /** Claims set by Grocery Restable that hooks and claim columns cannot override. */
    private const RESERVED_CLAIMS = ['sub', 'iat', 'nbf', 'exp', 'iss', 'aud', 'sid', 'jti', 'provider'];
    /** Seconds during which an already rotated refresh token is still accepted (parallel refreshes). */
    private const REFRESH_REUSE_LEEWAY = 10;

    /** @var array<string, list<callable>> */
    private array $hooks = [];

    /** @var (callable(string, string): ?bool)|null */
    private $legacyVerifier = null;

    /** @var callable|null */
    private $http = null;

    private ?PasswordHasher $hasher = null;

    public function __construct(
        private readonly GroceryRestable $api,
        private readonly string $jwtSecretOverride = '',
    ) {
    }

    // ------------------------------------------------------------------
    // Customization
    // ------------------------------------------------------------------

    /**
     * Extra token claims: fn (array $user, string $provider): array. Reserved claims are ignored.
     */
    public function claims(callable $callback): self
    {
        return $this->hook('claims', $callback);
    }

    /**
     * Compute the role instead of reading the role column: fn (array $user, string $provider): string.
     */
    public function role(callable $callback): self
    {
        $this->hooks['role'] = [$callback];

        return $this;
    }

    /**
     * Runs after the credentials are verified, before tokens are issued: fn (array $user, string $provider, Request $request).
     * Throw an ApiException to refuse the login (unverified email, banned user, outside office hours...).
     */
    public function beforeLogin(callable $callback): self
    {
        return $this->hook('beforeLogin', $callback);
    }

    /**
     * Runs after a successful login / refresh: fn (array $user, string $provider, array $tokens, Request $request).
     */
    public function afterLogin(callable $callback): self
    {
        return $this->hook('afterLogin', $callback);
    }

    /**
     * Shape the user object returned by /auth: fn (array $user): array (password and hidden columns already removed).
     */
    public function userPayload(callable $callback): self
    {
        $this->hooks['userPayload'] = [$callback];

        return $this;
    }

    /**
     * Columns of a user created by Google sign-in: fn (array $googleClaims, array $row): array (the returned row is inserted).
     */
    public function googleSignup(callable $callback): self
    {
        return $this->hook('googleSignup', $callback);
    }

    /**
     * Verify password hashes in formats not supported natively (e.g. salted legacy schemes):
     * fn (string $password, string $hash): ?bool - null means "not my format". Matching users are rehashed on login.
     */
    public function verifyLegacyPasswordUsing(callable $callback): self
    {
        $this->legacyVerifier = $callback;

        return $this;
    }

    /**
     * Use another password hasher (e.g. with different parameters than the settings).
     */
    public function setHasher(PasswordHasher $hasher): self
    {
        $this->hasher = $hasher;

        return $this;
    }

    /**
     * Replace the HTTP client used to talk to Google (proxies, tests).
     *
     * @param callable(string, string, array<string, string>): array{status: int, headers: array<string, string>, body: string} $http
     */
    public function setHttpClient(callable $http): self
    {
        $this->http = $http;

        return $this;
    }

    // ------------------------------------------------------------------
    // Public helpers
    // ------------------------------------------------------------------

    public function hasher(): PasswordHasher
    {
        // Built from the current settings each time (cheap), unless code set its own hasher.
        return $this->hasher ?? PasswordHasher::fromSettings($this->api->store()->settings(), $this->legacyVerifier);
    }

    /**
     * Hash a password with the configured algorithm (registration, password change, seeders).
     */
    public function hashPassword(string $password): string
    {
        return $this->hasher()->hash($password);
    }

    public function verifyPassword(string $password, ?string $hash): bool
    {
        return $this->hasher()->verify($password, $hash);
    }

    /**
     * Sign a user out on every device. Returns the number of revoked sessions.
     */
    public function revokeSessions(int|string $userId): int
    {
        return $this->api->store()->revokeUserAuthSessions((string) $userId);
    }

    public function isEnabled(?string $provider = null): bool
    {
        $store = $this->api->store();

        return match ($provider) {
            'password' => (bool) $store->setting('auth_password_enabled', false),
            'google' => (bool) $store->setting('auth_google_enabled', false),
            default => $store->setting('auth_password_enabled', false) || $store->setting('auth_google_enabled', false),
        };
    }

    /**
     * Sign-in methods for login screens (GET /auth).
     *
     * @return array<string, mixed>
     */
    public function methods(): array
    {
        $store = $this->api->store();
        $clientIds = (array) $store->setting('auth_google_client_ids', []);

        return [
            'password' => $this->isEnabled('password') ? ['identity' => array_values((array) $store->setting('auth_users_identity_columns', []))] : false,
            'google' => $this->isEnabled('google') && $clientIds !== [] ? ['client_id' => (string) $clientIds[0]] : false,
            'strategy' => $this->strategy(),
        ];
    }

    /**
     * Issue tokens for a user row, e.g. after your own login flow (OTP, magic link, SSO):
     *   return $api->auth()->issueTokens($user, 'otp', $ctx->request);
     *
     * @param array<string, mixed> $user Row of the users table.
     * @return array<string, mixed>
     */
    public function issueTokens(array $user, string $provider, Request $request): array
    {
        [$role, $claims] = $this->identity($user, $provider);
        $store = $this->api->store();
        $sessionTtl = max(60, (int) $store->setting('auth_session_ttl', 2592000));
        $userId = (string) $claims['sub'];

        if ($this->strategy() === 'session') {
            $session = $store->createAuthSession('session', $userId, $role, [], $provider, $sessionTtl, $request->clientIp(), (string) $request->header('user-agent'));
            $claims['sid'] = $session['id'];
            $store->updateAuthSession($session['id'], $role, $claims);
            $tokens = [
                'token_type' => 'Bearer',
                'access_token' => $session['token'],
                'expires_in' => $session['expires_at'] - time(),
                'expires_at' => $session['expires_at'],
            ];
        } else {
            $session = $store->createAuthSession('refresh', $userId, $role, $claims, $provider, $sessionTtl, $request->clientIp(), (string) $request->header('user-agent'));
            $tokens = $this->accessToken($claims, $session['id']) + [
                'refresh_token' => $session['token'],
                'refresh_expires_at' => $session['expires_at'],
            ];
        }

        $result = $tokens + ['user' => $this->payload($user)];
        $this->run('afterLogin', $user, $provider, $result, $request);

        return $result;
    }

    // ------------------------------------------------------------------
    // Endpoints
    // ------------------------------------------------------------------

    /**
     * POST /auth/login {identity | email | username, password}
     *
     * @return array<string, mixed>
     */
    public function login(Request $request): array
    {
        $this->assertEnabled('password');
        $body = $request->body();
        $identity = $body['identity'] ?? $body['email'] ?? $body['username'] ?? null;
        $password = $body['password'] ?? null;
        $errors = [];
        if (! is_string($identity) || trim($identity) === '' || mb_strlen($identity) > 255) {
            $errors['identity'] = 'The identity is required.';
        }
        if (! is_string($password) || $password === '') {
            $errors['password'] = 'The password is required.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $identity = trim($identity);
        PasswordHasher::assertLength($password);

        $store = $this->api->store();
        $window = max(60, (int) $store->setting('auth_attempts_window', 900));
        $keys = ['ip:' . $request->clientIp(), 'id:' . hash('sha256', mb_strtolower($identity))];
        $limits = [(int) $store->setting('auth_max_attempts_ip', 20), (int) $store->setting('auth_max_attempts', 5)];
        foreach ($keys as $i => $key) {
            $failures = $store->authFailures($key, $window);
            if ($limits[$i] > 0 && $failures['count'] >= $limits[$i]) {
                throw new ApiException('Too many failed login attempts. Try again in ' . $failures['retry_after'] . ' seconds.', 429, 'too_many_attempts', ['retry_after' => $failures['retry_after']]);
            }
        }

        $hasher = $this->hasher();
        $passwordColumn = $this->setting('auth_users_password_column');
        $user = $this->findByIdentity($identity);
        $hash = $user !== null && is_string($user[$passwordColumn] ?? null) ? $user[$passwordColumn] : null;
        if ($hash === null) {
            $hasher->dummyVerify($password); // unknown users must not answer faster
        }
        if ($user === null || ! $hasher->verify($password, $hash)) {
            $store->recordAuthFailure($keys);
            throw new ApiException('Invalid login credentials.', 401, 'invalid_credentials');
        }
        $store->clearAuthFailures($keys[1]);

        $this->assertActive($user);
        if ($store->setting('auth_password_rehash', true) && $hasher->needsRehash($hash)) {
            $this->updateUser($user, [$passwordColumn => $hasher->hash($password)]);
        }
        $this->run('beforeLogin', $user, 'password', $request);

        return $this->issueTokens($user, 'password', $request);
    }

    /**
     * POST /auth/google {id_token | credential} or {code, redirect_uri?, code_verifier?}
     *
     * @return array<string, mixed>
     */
    public function google(Request $request): array
    {
        $this->assertEnabled('google');
        $store = $this->api->store();
        $body = $request->body();
        $clientIds = array_values(array_filter(array_map('strval', (array) $store->setting('auth_google_client_ids', []))));
        $verifier = new GoogleVerifier($store, $this->http ?? new HttpClient());

        $idToken = $body['id_token'] ?? $body['credential'] ?? null;
        if (! is_string($idToken) || $idToken === '') {
            $code = $body['code'] ?? null;
            if (! is_string($code) || $code === '') {
                throw ValidationException::forField('id_token', 'Send the Google ID token ("id_token" or "credential") or an authorization "code".');
            }
            $redirectUri = (string) ($body['redirect_uri'] ?? 'postmessage');
            if ($redirectUri !== 'postmessage' && ! preg_match('#^https?://[^\s]+$#i', $redirectUri)) {
                throw ValidationException::forField('redirect_uri', 'Invalid redirect URI.');
            }
            $idToken = $verifier->exchangeCode($code, $clientIds[0] ?? '', (string) $store->setting('auth_google_client_secret', ''), $redirectUri,
                isset($body['code_verifier']) ? (string) $body['code_verifier'] : null);
        }
        $claims = $verifier->verifyIdToken($idToken, $clientIds, array_values((array) $store->setting('auth_google_hosted_domains', [])));

        $user = $this->findGoogleUser($claims) ?? $this->createGoogleUser($claims);
        $this->assertActive($user);
        $this->run('beforeLogin', $user, 'google', $request);

        return $this->issueTokens($user, 'google', $request);
    }

    /**
     * POST /auth/refresh {refresh_token}: new access token and a new (rotated) refresh token.
     *
     * @return array<string, mixed>
     */
    public function refresh(Request $request): array
    {
        if (! $this->isEnabled()) {
            throw ApiException::notFound();
        }
        $token = $request->body()['refresh_token'] ?? null;
        if (! is_string($token) || ! str_starts_with($token, 'grr_')) {
            throw ValidationException::forField('refresh_token', 'A refresh token is required.');
        }
        $store = $this->api->store();
        $rotated = $store->rotateAuthSession($token, self::REFRESH_REUSE_LEEWAY)
            ?? throw new ApiException('Invalid or expired refresh token.', 401, 'invalid_refresh_token');
        $session = $rotated['session'];

        // Read the user again: deleted or disabled users lose access, role / claim changes apply.
        $user = $this->findById($session['user_id']);
        try {
            if ($user === null) {
                throw new ApiException('Invalid or expired refresh token.', 401, 'invalid_refresh_token');
            }
            $this->assertActive($user);
            [$role, $claims] = $this->identity($user, (string) $session['provider']);
        } catch (ApiException $e) {
            $store->revokeAuthSession((string) $session['id']);
            throw $e;
        }
        $store->updateAuthSession((string) $session['id'], $role, $claims);

        $result = $this->accessToken($claims, (string) $session['id']) + [
            'refresh_token' => $rotated['token'],
            'refresh_expires_at' => (int) $session['expires_at'],
            'user' => $this->payload($user),
        ];
        $this->run('afterLogin', $user, (string) $session['provider'], $result, $request);

        return $result;
    }

    /**
     * POST /auth/logout {refresh_token?, all?}: ends the current session (or every session of the user).
     */
    public function logout(Request $request, AuthContext $auth): void
    {
        if (! $this->isEnabled()) {
            throw ApiException::notFound();
        }
        $store = $this->api->store();
        $body = $request->body();
        $sessionId = in_array($auth->method, ['jwt', 'session'], true) && is_string($auth->claim('sid')) ? $auth->claim('sid') : null;
        $userId = $sessionId !== null ? $auth->userId() : null;

        $refresh = $body['refresh_token'] ?? null;
        if (is_string($refresh) && $refresh !== '') {
            $session = $store->findAuthSession($refresh, 'refresh');
            if ($session !== null) {
                $sessionId ??= (string) $session['id'];
                $userId ??= $session['user_id'];
                $store->revokeAuthSession((string) $session['id']);
            }
        }
        if ($sessionId === null) {
            throw ApiException::unauthorized('Not signed in.');
        }
        $store->revokeAuthSession($sessionId);
        if (filter_var($body['all'] ?? false, FILTER_VALIDATE_BOOLEAN) && is_scalar($userId)) {
            $store->revokeUserAuthSessions((string) $userId);
        }
    }

    /**
     * GET /auth/me: the signed in user.
     *
     * @return array<string, mixed>
     */
    public function me(AuthContext $auth): array
    {
        if (! $this->isEnabled()) {
            throw ApiException::notFound();
        }
        $sub = $auth->userId();
        if (! in_array($auth->method, ['jwt', 'session'], true) || ! is_scalar($sub) || (string) $sub === '') {
            throw ApiException::unauthorized('Not signed in as a user.');
        }
        $user = $this->findById((string) $sub) ?? throw ApiException::notFound('User not found.');

        return $this->payload($user);
    }

    // ------------------------------------------------------------------
    // Tokens
    // ------------------------------------------------------------------

    private function strategy(): string
    {
        $strategy = (string) $this->api->store()->setting('auth_token_strategy', 'jwt');

        return in_array($strategy, self::STRATEGIES, true) ? $strategy : 'jwt';
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private function accessToken(array $claims, string $sessionId): array
    {
        $store = $this->api->store();
        $secret = $this->jwtSecretOverride !== '' ? $this->jwtSecretOverride : (string) $store->setting('jwt_secret', '');
        if ($secret === '') {
            throw new ConfigurationException('User login with the "jwt" strategy needs a JWT secret (Settings > Authentication).');
        }
        $now = time();
        $ttl = max(60, (int) $store->setting('auth_access_ttl', 900));
        $claims = ['sid' => $sessionId, 'iat' => $now, 'exp' => $now + $ttl] + $claims;
        if ((string) $store->setting('jwt_issuer', '') !== '') {
            $claims['iss'] = (string) $store->setting('jwt_issuer');
        }
        if ((string) $store->setting('jwt_audience', '') !== '') {
            $claims['aud'] = (string) $store->setting('jwt_audience');
        }

        return [
            'token_type' => 'Bearer',
            'access_token' => Jwt::encode($claims, $secret, (string) $store->setting('jwt_algorithm', 'HS256')),
            'expires_in' => $ttl,
            'expires_at' => $now + $ttl,
        ];
    }

    /**
     * Role and claims of a user (without time based claims).
     *
     * @param array<string, mixed> $user
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function identity(array $user, string $provider): array
    {
        $store = $this->api->store();
        $id = $user[$this->setting('auth_users_id_column')] ?? null;
        if (! is_scalar($id) || (string) $id === '') {
            throw new ConfigurationException('The users table row has no value in the id column.');
        }

        if (isset($this->hooks['role'])) {
            $role = ($this->hooks['role'][0])($user, $provider);
        } else {
            $roleColumn = (string) $store->setting('auth_users_role_column', '');
            $role = $roleColumn !== '' && is_scalar($user[$roleColumn] ?? null) && (string) $user[$roleColumn] !== ''
                ? (string) $user[$roleColumn]
                : (string) $store->setting('jwt_default_role', 'authenticated');
        }
        if (! is_string($role) || ! preg_match(self::ROLE_PATTERN, $role) || $role === AuthContext::ANON) {
            throw new ConfigurationException('The role of user ' . $id . ' is not a valid role name.');
        }
        if ($role === AuthContext::SERVICE_ROLE && ! $store->setting('jwt_allow_service_role', false)) {
            throw new ApiException('This account cannot sign in through the API.', 403, 'role_not_allowed');
        }

        $claims = [];
        foreach ((array) $store->setting('auth_users_claim_columns', []) as $column) {
            if (is_string($column) && array_key_exists($column, $user) && (is_scalar($user[$column]) || $user[$column] === null)) {
                $claims[$column] = $user[$column];
            }
        }
        foreach ($this->hooks['claims'] ?? [] as $callback) {
            $claims = (array) $callback($user, $provider) + $claims;
        }
        $claims = array_diff_key($claims, array_flip(self::RESERVED_CLAIMS));
        self::setPath($claims, (string) $store->setting('jwt_role_claim', 'role') ?: 'role', $role);

        return [$role, ['sub' => (string) $id, 'provider' => $provider] + $claims];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function setPath(array &$claims, string $path, mixed $value): void
    {
        $target = &$claims;
        foreach (explode('.', $path) as $segment) {
            if (! isset($target[$segment]) || ! is_array($target[$segment])) {
                $target[$segment] = [];
            }
            $target = &$target[$segment];
        }
        $target = $value;
    }

    // ------------------------------------------------------------------
    // Users table
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function payload(array $user): array
    {
        $hidden = [$this->setting('auth_users_password_column'), ...array_filter((array) $this->api->store()->setting('auth_users_hidden_columns', []), 'is_string')];
        $payload = array_diff_key($user, array_flip($hidden));
        if (isset($this->hooks['userPayload'])) {
            $payload = (array) ($this->hooks['userPayload'][0])($payload);
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $user
     */
    private function assertActive(array $user): void
    {
        $column = (string) $this->api->store()->setting('auth_users_active_column', '');
        if ($column === '') {
            return;
        }
        $value = $user[$column] ?? null;
        $inactive = $value === null || $value === false || (is_string($value) && in_array(strtolower(trim($value)), ['', '0', 'f', 'false', 'n', 'no', 'off'], true))
            || ((is_int($value) || is_float($value)) && $value == 0);
        if ($inactive) {
            throw new ApiException('This account is disabled.', 403, 'account_disabled');
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByIdentity(string $identity): ?array
    {
        foreach ((array) $this->api->store()->setting('auth_users_identity_columns', []) as $column) {
            $user = $this->findBy((string) $column, $identity, true);
            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findById(string $id): ?array
    {
        return $this->findBy($this->setting('auth_users_id_column'), $id);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBy(string $column, string $value, bool $caseInsensitive = false): ?array
    {
        [$db, $grammar, $table] = $this->users();
        $col = $table->column($column) ?? throw new ConfigurationException('The users table has no column "' . $column . '".');
        $wrapped = $grammar->wrap($column);
        // MySQL compares case-insensitively with its default collations; elsewhere lower both sides for text identities.
        $where = $caseInsensitive && $db->driver() !== 'mysql' && in_array($col->type, ['string', 'text'], true)
            ? 'LOWER(' . $wrapped . ') = LOWER(?)'
            : $wrapped . ' = ?';
        $rows = $db->select('SELECT * FROM ' . $grammar->wrapTable($table->name) . ' WHERE ' . $where . $grammar->limitOffset(2, null), [$value]);
        // An ambiguous identity (two rows) never signs anybody in.
        if (count($rows) !== 1) {
            return null;
        }
        // Native JSON types like the REST endpoints (drivers such as MySQLi return strings).
        $transformer = new RowTransformer();
        $user = [];
        foreach ($rows[0] as $name => $raw) {
            $type = $table->columns[$name]->type ?? 'string';
            $user[$name] = $transformer->cast($raw, $type, $type);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $values
     */
    private function updateUser(array $user, array $values): void
    {
        [$db, $grammar, $table] = $this->users();
        $idColumn = $this->setting('auth_users_id_column');
        $sets = implode(', ', array_map(static fn (string $c): string => $grammar->wrap($c) . ' = ?', array_keys($values)));
        $db->execute('UPDATE ' . $grammar->wrapTable($table->name) . ' SET ' . $sets . ' WHERE ' . $grammar->wrap($idColumn) . ' = ?', [...array_values($values), $user[$idColumn]]);
    }

    /**
     * @param array<string, mixed> $claims Verified Google claims.
     * @return array<string, mixed>|null
     */
    private function findGoogleUser(array $claims): ?array
    {
        $store = $this->api->store();
        [, , $table] = $this->users();
        $googleColumn = (string) $store->setting('auth_google_id_column', '');
        if ($googleColumn !== '' && $table->hasColumn($googleColumn)) {
            $user = $this->findBy($googleColumn, (string) $claims['sub']);
            if ($user !== null) {
                return $user;
            }
        }

        $emailColumn = (string) $store->setting('auth_google_email_column', '');
        $email = $claims['email'] ?? null;
        if (! $store->setting('auth_google_link_by_email', true) || $emailColumn === '' || ! is_string($email) || $email === '') {
            return null;
        }
        // Only a verified address may take over an existing account.
        if (! self::emailVerified($claims)) {
            return null;
        }
        $user = $this->findBy($emailColumn, $email, true);
        if ($user !== null && $googleColumn !== '' && $table->hasColumn($googleColumn)) {
            $existing = $user[$googleColumn] ?? null;
            if ($existing !== null && $existing !== '' && (string) $existing !== (string) $claims['sub']) {
                // Already linked to another Google account.
                throw new ApiException('This account is linked to another Google account.', 409, 'account_conflict');
            }
            $this->updateUser($user, [$googleColumn => (string) $claims['sub']]);
            $user[$googleColumn] = (string) $claims['sub'];
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $claims
     * @return array<string, mixed>
     */
    private function createGoogleUser(array $claims): array
    {
        $store = $this->api->store();
        if (! $store->setting('auth_google_signup', false)) {
            throw new ApiException('No account is linked to this Google account.', 403, 'account_not_found');
        }
        if (! self::emailVerified($claims)) {
            throw new ApiException('The email address of this Google account is not verified.', 403, 'email_not_verified');
        }
        [$db, $grammar, $table] = $this->users();
        $googleColumn = (string) $store->setting('auth_google_id_column', '');
        $emailColumn = (string) $store->setting('auth_google_email_column', '');

        $row = [];
        if ($emailColumn !== '') {
            $row[$emailColumn] = (string) $claims['email'];
        }
        if ($googleColumn !== '') {
            $row[$googleColumn] = (string) $claims['sub'];
        }
        foreach ((array) $store->setting('auth_google_signup_fields', []) as $column => $claim) {
            if (is_string($column) && is_string($claim) && is_scalar($claims[$claim] ?? null)) {
                $row[$column] = $claims[$claim];
            }
        }
        $passwordColumn = $this->setting('auth_users_password_column');
        $password = $table->column($passwordColumn);
        if ($password !== null && ! $password->isOptionalOnInsert()) {
            $row[$passwordColumn] = PasswordHasher::unusable();
        }
        foreach ($this->hooks['googleSignup'] ?? [] as $callback) {
            $row = (array) $callback($claims, $row);
        }
        $row = array_intersect_key($row, array_flip($table->columnNames()));
        if ($row === []) {
            throw new ConfigurationException('Google sign-up has no column to fill (check the Google id / email columns).');
        }

        $db->execute(
            'INSERT INTO ' . $grammar->wrapTable($table->name) . ' (' . implode(', ', array_map([$grammar, 'wrap'], array_keys($row))) . ') VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')',
            array_values($row),
        );

        $user = $googleColumn !== '' && isset($row[$googleColumn]) ? $this->findBy($googleColumn, (string) $row[$googleColumn]) : null;
        $user ??= $emailColumn !== '' && isset($row[$emailColumn]) ? $this->findBy($emailColumn, (string) $row[$emailColumn], true) : null;

        return $user ?? throw new ConfigurationException('The user created by Google sign-up could not be read back.');
    }

    /**
     * @param array<string, mixed> $claims
     */
    private static function emailVerified(array $claims): bool
    {
        $verified = $claims['email_verified'] ?? false;

        return $verified === true || $verified === 'true';
    }

    /**
     * @return array{0: ConnectionInterface, 1: Grammar, 2: Table}
     */
    private function users(): array
    {
        $store = $this->api->store();
        $connection = (string) $store->setting('auth_users_connection', '') ?: null;
        $name = $this->setting('auth_users_table');
        $table = $this->api->schema()->find($name, $connection)
            ?? throw new ConfigurationException('The users table "' . $name . '" does not exist.');
        $db = $this->api->connections()->get($connection);

        return [$db, Grammar::for($db->driver()), $table];
    }

    private function setting(string $key): string
    {
        $value = (string) $this->api->store()->setting($key, '');
        if ($value === '') {
            throw new ConfigurationException('User login: the setting "' . $key . '" is empty.');
        }

        return $value;
    }

    private function assertEnabled(string $provider): void
    {
        if (! $this->isEnabled($provider)) {
            throw ApiException::notFound(ucfirst($provider) . ' login is not enabled.');
        }
        if (! in_array((string) $this->api->store()->setting('auth_mode', 'api_key'), ['jwt', 'any'], true)) {
            throw new ApiException('User tokens are not accepted by this API: set the authentication mode to "API keys + user tokens" or "User tokens only".', 400, 'user_tokens_disabled');
        }
    }

    private function hook(string $name, callable $callback): self
    {
        $this->hooks[$name][] = $callback;

        return $this;
    }

    private function run(string $name, mixed ...$args): void
    {
        foreach ($this->hooks[$name] ?? [] as $callback) {
            $callback(...$args);
        }
    }
}
