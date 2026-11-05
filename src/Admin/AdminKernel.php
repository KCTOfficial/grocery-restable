<?php

declare(strict_types=1);

namespace GroceryRestable\Admin;

use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Auth\Authenticator;
use GroceryRestable\Auth\PasswordHasher;
use GroceryRestable\Auth\UserAuth;
use GroceryRestable\Database\ConnectionFactory;
use GroceryRestable\Database\ConnectionManager;
use GroceryRestable\Database\Grammar\Grammar;
use GroceryRestable\Database\Schema\Table;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Exception\ValidationException;
use GroceryRestable\GroceryRestable;
use GroceryRestable\Http\Request;
use GroceryRestable\Http\Response;
use GroceryRestable\Resource\CodeExporter;
use GroceryRestable\Resource\Resource;
use GroceryRestable\Resource\ResourceRegistry;
use GroceryRestable\Service\StorageService;
use GroceryRestable\Store\ConfigStore;
use GroceryRestable\Support\Jwt;
use GroceryRestable\Support\Uuid;
use Throwable;

/**
 * The phpMyAdmin-style admin GUI: setup wizard, login and a jQuery single page app
 * talking to JSON endpoints under {admin}/xhr.
 */
final class AdminKernel
{
    private const IDLE_TIMEOUT = 7200;
    private const MAX_FAILED_LOGINS = 5;
    private const THROTTLE_WINDOW = 900;
    /** XHR actions that only read; every other action is written to the admin log. */
    private const READ_ACTIONS = [
        'bootstrap', 'tables', 'table.structure', 'table.browse', 'resources.list', 'resource.get', 'resource.generate', 'resource.code',
        'connections.list', 'connection.test', 'apikeys.list', 'buckets.list', 'bucket.objects', 'settings.get', 'users.list',
        'openapi', 'console', 'jwt.generate', 'logs.days', 'logs.read', 'bucket.object.info', 'storage.scan',
    ];
    /** Content types of the files served from src/Admin/assets (anything else is refused). */
    private const ASSET_TYPES = [
        'js' => 'application/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'svg' => 'image/svg+xml',
        'woff2' => 'font/woff2',
    ];

    private View $view;
    private ?SessionInterface $session = null;

    public function __construct(private readonly GroceryRestable $api)
    {
        $this->view = new View();
    }

    public function handle(Request $request, SessionInterface $session): Response
    {
        $this->session = $session;
        $segments = $request->segments();
        $page = $segments[0] ?? '';
        $method = $request->method();

        try {
            $response = match (true) {
                $page === 'assets' && isset($segments[1]) => $this->asset(array_slice($segments, 1)),
                $page === 'setup' => $method === 'POST' ? $this->setupXhr($request) : $this->setupPage($request),
                $page === 'login' => $method === 'POST' ? $this->login($request) : $this->loginPage($request),
                $page === 'logout' && $method === 'POST' => $this->logout($request),
                $page === 'xhr' && $method === 'POST' => $this->xhr($request),
                $page === 'download' && $method === 'GET' => $this->download($request),
                $page === 'log' && $method === 'GET' => $this->downloadLog($request),
                $page === '' => $this->appPage($request),
                default => $this->html('Not found', 404),
            };
        } catch (ApiException $e) {
            $response = Response::json(['error' => ['code' => $e->getErrorCode(), 'message' => $e->getMessage(), 'details' => $e->getDetails() ?: null]], $e->getStatus());
        } catch (ConfigurationException $e) {
            $response = Response::json(['error' => ['code' => 'configuration_error', 'message' => $e->getMessage()]], 422);
        } catch (Throwable $e) {
            $this->api->log('error', 'Grocery Restable admin: ' . $e->getMessage(), ['exception' => $e]);
            $message = $this->api->config()->isDebug() ? $e->getMessage() : 'Internal error. Check the application log.';
            $response = Response::json(['error' => ['code' => 'internal_error', 'message' => $message]], 500);
        }

        return $this->secure($response);
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    private function appPage(Request $request): Response
    {
        $store = $this->api->store();
        if (! $store->isSetupCompleted()) {
            return Response::redirect($request->baseUrl() . '/setup');
        }
        $user = $this->currentUser();
        if ($user === null) {
            return Response::redirect($request->baseUrl() . '/login');
        }

        return $this->html($this->view->render('app', [
            'title' => $store->setting('app_name', 'Grocery Restable'),
            'assetUrl' => $request->baseUrl() . '/assets',
            'bootstrap' => [
                'adminUrl' => $request->baseUrl(),
                'apiUrl' => $this->api->config()->apiUrl(),
                'csrf' => $this->csrfToken(),
                'user' => $user['username'],
                'version' => GroceryRestable::VERSION,
            ],
        ]));
    }

    private function loginPage(Request $request, ?string $error = null, int $status = 200): Response
    {
        if (! $this->api->store()->isSetupCompleted()) {
            return Response::redirect($request->baseUrl() . '/setup');
        }
        if ($this->currentUser() !== null) {
            return Response::redirect($request->baseUrl() . '/');
        }

        return $this->html($this->view->render('login', [
            'title' => $this->api->store()->setting('app_name', 'Grocery Restable'),
            'assetUrl' => $request->baseUrl() . '/assets',
            'action' => $request->baseUrl() . '/login',
            'csrf' => $this->csrfToken(),
            'error' => $error,
        ]), $status);
    }

    private function login(Request $request): Response
    {
        $body = $request->body();
        if (! $this->validCsrf((string) ($body['_csrf'] ?? ''))) {
            return $this->loginPage($request, 'Your session expired. Please try again.', 419);
        }

        $store = $this->api->store();
        $ip = $request->clientIp() !== '' ? $request->clientIp() : 'unknown';
        if ($store->recentFailedLogins($ip, self::THROTTLE_WINDOW) >= self::MAX_FAILED_LOGINS) {
            $this->api->logs()->admin('login.throttled', ['ip' => $ip], 'warning');

            return $this->loginPage($request, 'Too many failed attempts. Try again in 15 minutes.', 429);
        }

        $username = trim((string) ($body['username'] ?? ''));
        $user = $store->verifyUser($username, (string) ($body['password'] ?? ''));
        if ($user === null) {
            $store->recordFailedLogin($ip, $username);
            $this->api->logs()->admin('login.failed', ['ip' => $ip, 'user' => mb_substr($username, 0, 64)], 'warning');

            return $this->loginPage($request, 'Invalid username or password.', 401);
        }

        $store->clearFailedLogins($ip);
        $this->api->logs()->admin('login', ['ip' => $ip, 'user' => $user['username']]);
        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        $this->session->set('last_activity', time());
        $this->session->set('csrf', bin2hex(random_bytes(32)));

        return Response::redirect($request->baseUrl() . '/');
    }

    private function logout(Request $request): Response
    {
        $token = (string) ($request->body()['_csrf'] ?? $request->header('x-csrf-token') ?? '');
        if ($this->validCsrf($token)) {
            $this->session->remove('user_id');
            $this->session->remove('csrf');
            $this->session->regenerate();
        }

        return Response::redirect($request->baseUrl() . '/login');
    }

    private function setupPage(Request $request): Response
    {
        $this->guardSetup($request);

        $providers = array_filter($this->api->connections()->all(), static fn (array $c): bool => $c['source'] === 'framework');

        return $this->html($this->view->render('setup', [
            'title' => 'Grocery Restable setup',
            'assetUrl' => $request->baseUrl() . '/assets',
            'bootstrap' => [
                'adminUrl' => $request->baseUrl(),
                'apiUrl' => $this->api->config()->apiUrl(),
                'csrf' => $this->csrfToken(),
                'framework' => $this->api->config()->framework(),
                'frameworks' => $this->api->config()->frameworkChoices(),
                'engines' => ConnectionFactory::catalog(),
                'frameworkConnections' => array_values($providers),
                'version' => GroceryRestable::VERSION,
            ],
        ]));
    }

    private function setupXhr(Request $request): Response
    {
        $this->guardSetup($request);
        if (! $this->validCsrf((string) $request->header('x-csrf-token'))) {
            throw new ApiException('Invalid CSRF token. Reload the page.', 419, 'csrf');
        }
        $body = $request->body();

        return match ($body['action'] ?? '') {
            'test' => Response::json($this->setupTest($body)),
            'finish' => Response::json($this->setupFinish($body)),
            default => throw ApiException::badRequest('Unknown setup action.'),
        };
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function setupTest(array $body): array
    {
        return (new Installer($this->api))->testDatabase((array) ($body['database'] ?? []));
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function setupFinish(array $body): array
    {
        $keys = (new Installer($this->api))->install(
            (string) ($body['framework'] ?? ''),
            (array) ($body['database'] ?? []),
            (array) ($body['account'] ?? []),
            (array) ($body['options'] ?? []),
        );

        return ['ok' => true, 'keys' => $keys, 'loginUrl' => 'login'];
    }

    private function guardSetup(Request $request): void
    {
        if ($this->api->store()->isSetupCompleted()) {
            throw ApiException::notFound('Setup has already been completed.');
        }

        $token = (string) $this->api->config()->get('setupToken', '');
        if ($token !== '') {
            $given = (string) ($request->queryParam('token') ?? $this->session->get('setup_token') ?? '');
            if (! hash_equals($token, $given)) {
                throw ApiException::forbidden('Open the setup wizard with ?token=<setupToken>.');
            }
            $this->session->set('setup_token', $given);

            return;
        }

        $trusted = (array) $this->api->config()->get('trustedSetupIps', ['127.0.0.1', '::1']);
        if (! in_array($request->clientIp(), $trusted, true)) {
            throw ApiException::forbidden('The setup wizard is only reachable from a trusted IP. Configure "setupToken" to run it remotely.');
        }
    }

    /**
     * Serve a static file of src/Admin/assets: the GUI scripts / styles, the favicon and the
     * offline copies of the CDN libraries (assets/vendor, used only when the CDN is unreachable).
     *
     * @param list<string> $segments
     */
    private function asset(array $segments): Response
    {
        foreach ($segments as $segment) {
            if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $segment) || str_contains($segment, '..')) {
                return $this->html('Not found', 404);
            }
        }
        $extension = strtolower(pathinfo((string) end($segments), PATHINFO_EXTENSION));
        $root = realpath(__DIR__ . '/assets');
        $file = realpath(__DIR__ . '/assets/' . implode('/', $segments));
        if (! isset(self::ASSET_TYPES[$extension]) || $root === false || $file === false || ! is_file($file)
            || ! str_starts_with($file, $root . DIRECTORY_SEPARATOR)) {
            return $this->html('Not found', 404);
        }

        // Vendor files are versioned copies of CDN releases and never change.
        $cache = $segments[0] === 'vendor' ? 'public, max-age=31536000, immutable' : 'public, max-age=3600';

        return Response::text((string) file_get_contents($file), self::ASSET_TYPES[$extension], 200, [
            'Cache-Control' => $cache,
            'ETag' => '"' . md5(GroceryRestable::VERSION . filemtime($file)) . '"',
        ]);
    }

    /**
     * Download a storage object from the storage browser (admin session required).
     */
    private function download(Request $request): Response
    {
        if ($this->currentUser() === null) {
            throw ApiException::unauthorized();
        }
        $file = $this->api->storage()->download((string) $request->queryParam('bucket'), (string) $request->queryParam('path'), AuthContext::service('admin'));

        return Response::file($file['path'], $file['download_name'], $file['mime'], false);
    }

    /**
     * Download the JSON log of a day (admin session required).
     */
    private function downloadLog(Request $request): Response
    {
        if ($this->currentUser() === null) {
            throw ApiException::unauthorized();
        }
        $day = (string) $request->queryParam('day');
        $path = $this->api->logs()->path($day);
        if (! is_file($path)) {
            throw ApiException::notFound('No log for ' . $day . '.');
        }

        return Response::file($path, 'grocery-restable-' . $day . '.jsonl', 'application/x-ndjson', false);
    }

    // ------------------------------------------------------------------
    // XHR
    // ------------------------------------------------------------------

    private function xhr(Request $request): Response
    {
        $user = $this->currentUser() ?? throw ApiException::unauthorized('Your session has expired. Please log in again.');
        if (! $this->validCsrf((string) $request->header('x-csrf-token'))) {
            throw new ApiException('Invalid CSRF token. Reload the page.', 419, 'csrf');
        }

        $body = $request->body();
        $action = (string) ($body['action'] ?? '');
        $store = $this->api->store();
        $registry = $this->api->registry();
        if (! in_array($action, self::READ_ACTIONS, true)) {
            $this->api->logs()->admin($action, array_filter([
                'user' => $user['username'],
                'ip' => $request->clientIp(),
                'target' => $this->actionTarget($body),
            ], static fn (mixed $v): bool => $v !== null && $v !== ''));
        }

        $result = match ($action) {
            'bootstrap' => $this->bootstrapData($user),
            'tables' => $this->tables((string) ($body['connection'] ?? '')),
            'table.structure' => $this->structure($this->table($body), $body),
            'table.browse' => $this->browse($body),
            'table.row.save' => $this->saveRow($body),
            'table.row.delete' => $this->deleteRow($body),
            'resources.list' => $this->resourceList(),
            'resource.get' => $this->resourceGet($body),
            'resource.generate' => $registry->generate((string) ($body['table'] ?? ''), $this->connectionName($body))->toArray(),
            'resource.save' => $this->resourceSave($body),
            'resource.delete' => $this->resourceDelete($body),
            'resource.code' => ['code' => (new CodeExporter())->export(Resource::fromArray((array) ($body['definition'] ?? [])))],
            'connections.list' => array_values($this->api->connections()->all()),
            'connection.save' => $this->connectionSave($body),
            'connection.delete' => $this->connectionDelete($body),
            'connection.test' => $this->connectionTest($body),
            'connection.default' => $this->connectionDefault($body),
            'apikeys.list' => $store->apiKeys(),
            'apikey.create' => ['key' => $store->createApiKey((string) ($body['name'] ?? ''), (string) ($body['role'] ?? 'authenticated'))],
            'apikey.revoke' => $this->done($store->revokeApiKey((int) ($body['id'] ?? 0))),
            'apikey.delete' => $this->done($store->deleteApiKey((int) ($body['id'] ?? 0))),
            'buckets.list' => array_values($this->api->storage()->buckets()),
            'bucket.save' => $this->bucketSave($body),
            'bucket.delete' => $this->bucketDelete($body),
            'bucket.objects' => $this->api->storage()->listObjects(
                (string) ($body['bucket'] ?? ''),
                (string) ($body['prefix'] ?? ''),
                AuthContext::service('admin'),
                (string) ($body['delimiter'] ?? '/'),
                (int) ($body['limit'] ?? StorageService::MAX_LIST),
                (string) ($body['cursor'] ?? ''),
            ),
            'bucket.object.info' => $this->api->storage()->info((string) ($body['bucket'] ?? ''), (string) ($body['path'] ?? ''), AuthContext::service('admin')),
            'bucket.reindex' => $this->api->storage()->reindex((string) ($body['bucket'] ?? '')),
            'storage.scan' => $this->api->janitor()->scan(isset($body['minAgeHours']) ? (int) $body['minAgeHours'] : null),
            'storage.clean' => $this->api->janitor()->clean(array_map('strval', (array) ($body['tasks'] ?? [])), isset($body['minAgeHours']) ? (int) $body['minAgeHours'] : null),
            'bucket.upload' => $this->bucketUpload($request, $body),
            'bucket.object.delete' => $this->done($this->api->storage()->delete((string) ($body['bucket'] ?? ''), (string) ($body['path'] ?? ''), AuthContext::service('admin'))),
            'settings.get' => $this->settingsGet(),
            'settings.save' => $this->settingsSave((array) ($body['settings'] ?? [])),
            'users.list' => $store->users(),
            'user.create' => ['id' => $store->createUser((string) ($body['username'] ?? ''), (string) ($body['password'] ?? ''))],
            'user.update' => $this->userUpdate($body, $user),
            'account.update' => $this->accountUpdate($body, $user),
            'user.delete' => $this->userDelete($body, $user),
            'schema.refresh' => $this->done($this->api->schema()->refresh(isset($body['connection']) ? (string) $body['connection'] : null)),
            'openapi' => $this->api->openApi($this->api->config()->apiUrl()),
            'console' => $this->console($body),
            'jwt.generate' => $this->jwtGenerate($body),
            'config.export' => $store->export(),
            'config.import' => $this->import((array) ($body['data'] ?? [])),
            'logs.days' => $this->api->logs()->days(),
            'logs.read' => $this->api->logs()->read(
                (string) ($body['day'] ?? date('Y-m-d')),
                array_intersect_key((array) ($body['filters'] ?? []), array_flip(['type', 'level', 'method', 'status', 'resource', 'search'])),
                (int) ($body['page'] ?? 1),
                (int) ($body['perPage'] ?? 50),
            ),
            'logs.delete' => ['deleted' => isset($body['day'])
                ? (int) $this->api->logs()->delete((string) $body['day'])
                : $this->api->logs()->deleteOlderThan((int) ($body['keepDays'] ?? 0))],
            default => throw ApiException::badRequest('Unknown action "' . $action . '".'),
        };

        return Response::json(['data' => $result]);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function bootstrapData(array $user): array
    {
        $store = $this->api->store();
        $warnings = [];
        if ($store->setting('auth_mode') === 'none') {
            $warnings[] = 'Authentication is disabled (auth mode "none"): anybody can read and write every exposed resource.';
        }
        if ($this->api->config()->isDebug()) {
            $warnings[] = 'Debug mode is enabled: error details are returned to API clients.';
        }
        if ((string) $this->api->config()->get('encryptionKey', '') === '') {
            $warnings[] = 'No encryptionKey configured: secrets are encrypted with the generated key file in the storage directory. Back it up or set encryptionKey.';
        }
        if (! $store->setting('rate_limit_enabled', true)) {
            $warnings[] = 'Rate limiting is disabled: clients can send unlimited requests.';
        }
        if ($store->setting('jwt_allow_service_role', false)) {
            $warnings[] = 'JWTs may grant the service_role role (full access). Only enable this when you control every token issuer.';
        }
        if (in_array('*', (array) $store->setting('cors_origins', []), true)) {
            $warnings[] = 'CORS allows every origin ("*").';
        }
        if ($this->api->auth()->isEnabled() && ! in_array($store->setting('auth_mode'), ['jwt', 'any'], true)) {
            $warnings[] = 'User login is enabled but the authentication mode does not accept user tokens: /auth answers 400.';
        }

        return [
            'user' => ['id' => $user['id'], 'username' => $user['username']],
            'settings' => $this->settingsGet(),
            'connections' => array_values($this->api->connections()->all()),
            'frameworks' => $this->api->config()->frameworkChoices(),
            'framework' => $store->setting('framework') ?? $this->api->config()->framework(),
            'engines' => ConnectionFactory::catalog(),
            'fieldTypes' => Resource::FIELD_TYPES,
            'permissions' => Resource::PERMISSIONS,
            'operations' => Resource::OPERATIONS,
            'authModes' => Authenticator::MODES,
            'passwordAlgorithms' => PasswordHasher::availableAlgorithms(),
            'legacyDigests' => array_keys(PasswordHasher::LEGACY),
            'idGenerators' => Uuid::GENERATORS,
            'logPath' => $this->api->config()->logPath(),
            'trashPath' => $this->api->janitor()->trashRoot(),
            'reserved' => ResourceRegistry::RESERVED,
            'apiUrl' => $this->api->config()->apiUrl(),
            'warnings' => $warnings,
            'overview' => $this->overview(),
            'counts' => [
                'resources' => count($this->api->registry()->all()),
                'apiKeys' => count(array_filter($store->apiKeys(), static fn (array $k): bool => $k['revoked_at'] === null)),
                'buckets' => count($this->api->storage()->buckets()),
                'users' => $store->countUsers(),
                'functions' => count($this->api->functions()),
            ],
        ];
    }

    /**
     * Tables of the default connection and how many are exposed (dashboard "Getting started").
     *
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        $connection = $this->api->connections()->defaultName();
        try {
            $tables = $this->tables($connection);
        } catch (Throwable $e) {
            return ['connection' => $connection, 'tables' => 0, 'exposed' => 0, 'error' => $e->getMessage()];
        }
        $exposed = array_filter($tables, static fn (array $t): bool => $t['resources'] !== []);

        return [
            'connection' => $connection,
            'tables' => count($tables),
            'exposed' => count($exposed),
            'error' => null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tables(string $connection): array
    {
        $connection = $connection !== '' ? $connection : $this->api->connections()->defaultName();
        $exposed = [];
        foreach ($this->api->registry()->all() as $resource) {
            if (($resource->getConnection() ?? $this->api->connections()->defaultName()) === $connection) {
                $exposed[$resource->getTable()][] = ['name' => $resource->getName(), 'source' => $resource->getSource()];
            }
        }
        $result = [];
        foreach ($this->api->schema()->tables($connection) as $name => $table) {
            $result[] = [
                'name' => $name,
                'type' => $table->type,
                'columns' => count($table->columns),
                'primaryKey' => $table->primaryKey,
                'resources' => $exposed[$name] ?? [],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function structure(Table $table, array $body): array
    {
        $connection = $this->connectionName($body);

        return [
            'table' => $table->toArray(),
            'referencedBy' => array_map(static fn ($fk): array => $fk->toArray(), $this->api->schema()->referencingKeys($table->name, $connection)),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function browse(array $body): array
    {
        $table = $this->table($body);
        $db = $this->api->connections()->get($this->connectionName($body));
        $grammar = Grammar::for($db->driver());
        $perPage = max(1, min(500, (int) ($body['perPage'] ?? 25)));
        $page = max(1, (int) ($body['page'] ?? 1));

        $where = '';
        $bindings = [];
        $search = trim((string) ($body['search'] ?? ''));
        if ($search !== '') {
            $parts = [];
            foreach ($table->columns as $name => $column) {
                if ($column->type === 'binary') {
                    continue;
                }
                $parts[] = $grammar->ilike($grammar->wrap($name));
                $bindings[] = '%' . $search . '%';
            }
            $where = $parts !== [] ? ' WHERE ' . implode(' OR ', $parts) : '';
        }

        $order = '';
        if (! empty($body['order']) && $table->hasColumn((string) $body['order'])) {
            $order = ' ORDER BY ' . $grammar->wrap((string) $body['order']) . (strtolower((string) ($body['dir'] ?? 'asc')) === 'desc' ? ' DESC' : ' ASC');
        } elseif ($table->primaryKey !== []) {
            $order = ' ORDER BY ' . implode(', ', array_map($grammar->wrap(...), $table->primaryKey));
        }

        $total = (int) ($db->select('SELECT COUNT(*) AS c FROM ' . $grammar->wrapTable($table->name) . $where, $bindings)[0]['c'] ?? 0);
        $rows = $db->select('SELECT * FROM ' . $grammar->wrapTable($table->name) . $where . $order . $grammar->limitOffset($perPage, ($page - 1) * $perPage), $bindings);

        foreach ($rows as &$row) {
            foreach ($row as $column => $value) {
                if (is_resource($value)) {
                    $value = stream_get_contents($value);
                }
                if (is_string($value) && (($table->columns[$column]->type ?? '') === 'binary' || ! mb_check_encoding($value, 'UTF-8'))) {
                    $row[$column] = '[BINARY ' . strlen($value) . ' bytes]';
                } elseif (is_string($value) && mb_strlen($value) > 1000) {
                    $row[$column] = mb_substr($value, 0, 1000) . '…';
                } else {
                    $row[$column] = $value;
                }
            }
        }
        unset($row);

        return [
            'columns' => array_map(static fn ($c): array => $c->toArray(), array_values($table->columns)),
            'primaryKey' => $table->primaryKey,
            'rows' => $rows,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function saveRow(array $body): array
    {
        $table = $this->table($body);
        $db = $this->api->connections()->get($this->connectionName($body));
        $grammar = Grammar::for($db->driver());
        $values = (array) ($body['values'] ?? []);
        foreach (array_keys($values) as $column) {
            if (! $table->hasColumn((string) $column)) {
                throw ValidationException::forField((string) $column, 'Unknown column.');
            }
        }
        $values = array_map(static fn (mixed $v): mixed => is_bool($v) ? (int) $v : $v, $values);
        $key = $body['key'] ?? null; // primary key values of the edited row, null = insert

        if ($key === null) {
            if ($values === []) {
                throw ApiException::badRequest('Nothing to insert.');
            }
            $affected = $db->execute(
                'INSERT INTO ' . $grammar->wrapTable($table->name) . ' (' . implode(', ', array_map($grammar->wrap(...), array_keys($values))) . ') VALUES ('
                . implode(', ', array_fill(0, count($values), '?')) . ')',
                array_values($values),
            );

            return ['affected' => $affected];
        }

        [$whereSql, $whereBindings] = $this->keyWhere($table, $grammar, (array) $key);
        if ($values === []) {
            return ['affected' => 0];
        }
        $before = $db->select('SELECT * FROM ' . $grammar->wrapTable($table->name) . $whereSql, $whereBindings)[0] ?? null;
        $sets = implode(', ', array_map(static fn (string $c): string => $grammar->wrap($c) . ' = ?', array_keys($values)));
        $affected = $db->execute('UPDATE ' . $grammar->wrapTable($table->name) . ' SET ' . $sets . $whereSql, [...array_values($values), ...$whereBindings]);
        // A replaced file name leaves the old upload behind: delete it unless another row uses it.
        $released = $before !== null ? $this->api->janitor()->releaseRowFiles($this->connectionName($body), $table->name, $before, $values) : 0;

        return ['affected' => $affected, 'filesDeleted' => $released];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function deleteRow(array $body): array
    {
        $table = $this->table($body);
        $db = $this->api->connections()->get($this->connectionName($body));
        $grammar = Grammar::for($db->driver());
        [$whereSql, $bindings] = $this->keyWhere($table, $grammar, (array) ($body['key'] ?? []));
        $before = $db->select('SELECT * FROM ' . $grammar->wrapTable($table->name) . $whereSql, $bindings)[0] ?? null;
        $affected = $db->execute('DELETE FROM ' . $grammar->wrapTable($table->name) . $whereSql, $bindings);
        // Like the API, deleting a row deletes its upload files (setting delete_replaced_files).
        $released = $before !== null && $affected > 0 ? $this->api->janitor()->releaseRowFiles($this->connectionName($body), $table->name, $before) : 0;

        return ['affected' => $affected, 'filesDeleted' => $released];
    }

    /**
     * @param array<string, mixed> $key
     * @return array{0: string, 1: list<mixed>}
     */
    private function keyWhere(Table $table, Grammar $grammar, array $key): array
    {
        if ($table->primaryKey === []) {
            throw ApiException::badRequest('Rows of tables without a primary key cannot be edited here.');
        }
        $parts = [];
        $bindings = [];
        foreach ($table->primaryKey as $column) {
            if (! array_key_exists($column, $key)) {
                throw ApiException::badRequest('Missing primary key value for "' . $column . '".');
            }
            $parts[] = $grammar->wrap($column) . ' = ?';
            $bindings[] = $key[$column];
        }

        return [' WHERE ' . implode(' AND ', $parts), $bindings];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resourceList(): array
    {
        $registry = $this->api->registry();
        $stored = $this->api->store()->resources();
        $list = [];
        foreach ($registry->all() as $name => $resource) {
            $list[$name] = $this->resourceSummary($resource, true);
        }
        // Disabled or broken GUI definitions are not part of all(): list them too.
        foreach ($stored as $name => $row) {
            if (isset($list[$name])) {
                continue;
            }
            try {
                $resource = Resource::fromArray(['name' => $name] + $row['definition'])->setSource('gui');
                $list[$name] = $this->resourceSummary($resource, $row['enabled']) + ['shadowed' => isset($registry->codeResources()[$name])];
            } catch (ConfigurationException $e) {
                $list[$name] = ['name' => $name, 'source' => 'gui', 'enabled' => $row['enabled'], 'problems' => [$e->getMessage()]];
            }
        }
        ksort($list);

        return array_values($list);
    }

    /**
     * @return array<string, mixed>
     */
    private function resourceSummary(Resource $resource, bool $enabled): array
    {
        $problems = [];
        try {
            $ctx = $this->api->registry()->resolve($resource);
            $problems = $resource->problems($ctx->table);
        } catch (Throwable $e) {
            $problems[] = $e->getMessage();
        }

        return [
            'name' => $resource->getName(),
            'table' => $resource->getTable(),
            'connection' => $resource->getConnection() ?? $this->api->connections()->defaultName(),
            'subject' => $resource->getSubject(),
            'source' => $resource->getSource(),
            'enabled' => $enabled,
            'relations' => count($resource->getRelations()),
            'uploads' => count($resource->getUploads()),
            'operations' => $resource->getOperations(),
            'permissions' => $resource->getPermissions(),
            'problems' => $problems,
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function resourceGet(array $body): array
    {
        $name = (string) ($body['name'] ?? '');
        $registry = $this->api->registry();
        $stored = $this->api->store()->resources()[$name] ?? null;

        $code = $registry->codeResources()[$name] ?? null;
        if ($code !== null) {
            $resource = $code;
            $enabled = true;
        } elseif ($stored !== null) {
            $resource = Resource::fromArray(['name' => $name] + $stored['definition'])->setSource('gui');
            $enabled = $stored['enabled'];
        } else {
            $resource = $registry->find($name) ?? throw ApiException::notFound('Unknown resource "' . $name . '".');
            $enabled = true;
        }

        $summary = $this->resourceSummary($resource, $enabled);

        return [
            'definition' => $resource->toArray(),
            'source' => $resource->getSource(),
            'enabled' => $enabled,
            'readOnly' => $resource->getSource() === 'code',
            'problems' => $summary['problems'],
            'codeFeatures' => [
                'callbacks' => $resource->getSource() === 'code',
                'actions' => array_keys($resource->getActions()),
                'scopes' => count($resource->getScopes()),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function resourceSave(array $body): array
    {
        $definition = (array) ($body['definition'] ?? []);
        $name = (string) ($definition['name'] ?? '');
        $original = (string) ($body['original'] ?? $name);
        ConfigStore::assertName($name, 'resource');
        if (in_array(strtolower($name), ResourceRegistry::RESERVED, true)) {
            throw ValidationException::forField('name', '"' . $name . '" is a reserved name.');
        }
        if (isset($this->api->registry()->codeResources()[$name])) {
            throw ValidationException::forField('name', 'A resource with this name is defined in code.');
        }
        if ($name !== $original && isset($this->api->store()->resources()[$name])) {
            throw ValidationException::forField('name', 'A resource with this name already exists.');
        }

        // Round-trip through the model: validates every value and drops unknown keys.
        $resource = Resource::fromArray($definition);
        $ctx = $this->api->registry()->resolve($resource);
        $problems = array_values(array_filter(
            $resource->problems($ctx->table),
            static fn (string $p): bool => ! str_starts_with($p, 'The table has no single-column primary key'),
        ));
        if ($problems !== []) {
            throw new ApiException('The resource definition is invalid.', 422, 'invalid_resource', ['problems' => $problems]);
        }
        foreach ($resource->getRelations() as $relation) {
            if (! $this->api->schema()->has($relation->table, $ctx->connectionName)) {
                throw new ApiException('Relation "' . $relation->name . '" references unknown table "' . $relation->table . '".', 422, 'invalid_resource');
            }
        }

        $store = $this->api->store();
        if ($original !== $name) {
            $store->deleteResource($original);
        }
        $store->saveResource($name, $resource->toArray(), (bool) ($body['enabled'] ?? true));
        $this->api->registry()->reload();

        return ['name' => $name];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function resourceDelete(array $body): array
    {
        $this->api->store()->deleteResource((string) ($body['name'] ?? ''));
        $this->api->registry()->reload();

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function connectionSave(array $body): array
    {
        $name = (string) ($body['name'] ?? '');
        if ($this->api->connections()->isFrameworkConnection($name)) {
            throw ValidationException::forField('name', 'This name is used by a framework connection.');
        }
        $config = $this->connectionConfig((array) ($body['config'] ?? []));
        $existing = $this->api->store()->connection($name);
        if (($config['password'] ?? '') === '' && empty($body['config']['clearPassword']) && $existing !== null) {
            $config['password'] = $existing['password']; // keep the stored password when the field is left empty
        }
        $this->api->store()->saveConnection($name, $config);
        $this->api->connections()->forget($name);
        $this->api->schema()->refresh($name);

        return ['name' => $name];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function connectionDelete(array $body): array
    {
        $name = (string) ($body['name'] ?? '');
        if ($name === $this->api->connections()->defaultName()) {
            throw ApiException::badRequest('The default connection cannot be deleted.');
        }
        $this->api->store()->deleteConnection($name);
        $this->api->connections()->forget($name);

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function connectionTest(array $body): array
    {
        if (isset($body['name']) && ! isset($body['config'])) {
            try {
                $this->api->connections()->forget((string) $body['name']);
                $this->api->connections()->get((string) $body['name'])->select('SELECT 1');

                return ['ok' => true, 'message' => 'Connection successful.'];
            } catch (Throwable $e) {
                return ['ok' => false, 'message' => $e->getMessage()];
            }
        }
        $config = $this->connectionConfig((array) ($body['config'] ?? []));
        if (($config['password'] ?? '') === '' && isset($body['name']) && ($existing = $this->api->store()->connection((string) $body['name'])) !== null) {
            $config['password'] = $existing['password'];
        }
        $error = ConnectionManager::test($config);

        return ['ok' => $error === null, 'message' => $error ?? 'Connection successful.'];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function connectionDefault(array $body): array
    {
        $name = (string) ($body['name'] ?? '');
        if (! $this->api->connections()->has($name)) {
            throw ApiException::notFound('Unknown connection.');
        }
        $this->api->store()->saveSettings(['default_connection' => $name]);
        $this->api->registry()->reload();

        return ['ok' => true];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function connectionConfig(array $input): array
    {
        return Installer::connectionConfig($input);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function bucketSave(array $body): array
    {
        $name = (string) ($body['name'] ?? '');
        $this->api->store()->saveBucket($name, StorageService::normalizeDefinition((array) ($body['definition'] ?? [])));
        $this->api->storage()->directory($name);

        return ['name' => $name];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function bucketUpload(Request $request, array $body): array
    {
        $file = $request->file('file') ?? throw ValidationException::forField('file', 'Choose a file.');

        return $this->api->storage()->upload(
            (string) ($body['bucket'] ?? ''),
            $file,
            (string) ($body['path'] ?? ''),
            null,
            filter_var($body['upsert'] ?? false, FILTER_VALIDATE_BOOLEAN),
            AuthContext::service('admin'),
            ['cacheControl' => $body['cache_control'] ?? null, 'metadata' => $body['metadata'] ?? null],
        );
    }

    /**
     * Delete a bucket definition; with "purge" its files go to the trash, otherwise they stay on disk
     * (and are reported as an orphan bucket by the storage cleanup).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function bucketDelete(array $body): array
    {
        $name = (string) ($body['name'] ?? '');
        $this->api->store()->deleteBucket($name);
        $trashed = ! empty($body['purge']) && $this->api->janitor()->trashBucket($name);

        return ['ok' => true, 'trashed' => $trashed];
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsGet(): array
    {
        $settings = $this->api->store()->settings();
        foreach (ConfigStore::SECRET_SETTINGS as $secret) {
            $settings[$secret . '_set'] = ($settings[$secret] ?? '') !== '' || ($secret === 'jwt_secret' && (string) $this->api->config()->get('jwtSecret', '') !== '');
            unset($settings[$secret]);
        }
        $settings['jwt_secret_from_config'] = (string) $this->api->config()->get('jwtSecret', '') !== '';

        return $settings;
    }

    /**
     * Normalize and validate the user login settings (auth_*), checking the users table when login is enabled.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function userAuthSettings(array $values): array
    {
        $store = $this->api->store();
        if (array_key_exists('auth_google_client_secret', $values) && ($values['auth_google_client_secret'] === '' || $values['auth_google_client_secret'] === null)) {
            unset($values['auth_google_client_secret']); // empty means "keep the current secret"
        }
        if (isset($values['auth_token_strategy']) && ! in_array($values['auth_token_strategy'], UserAuth::STRATEGIES, true)) {
            throw ValidationException::forField('auth_token_strategy', 'Use jwt or session.');
        }
        if (isset($values['auth_password_algorithm']) && ! in_array($values['auth_password_algorithm'], PasswordHasher::availableAlgorithms(), true)) {
            throw ValidationException::forField('auth_password_algorithm', 'This algorithm is not available on this server.');
        }
        $ranges = [
            'auth_access_ttl' => [60, 86400], 'auth_session_ttl' => [300, 31536000], 'auth_password_cost' => [10, 15],
            'auth_password_memory' => [19456, 1048576], 'auth_password_time' => [2, 10], 'auth_max_attempts' => [0, 1000],
            'auth_max_attempts_ip' => [0, 10000], 'auth_attempts_window' => [60, 86400],
        ];
        foreach ($ranges as $key => [$min, $max]) {
            if (isset($values[$key])) {
                $values[$key] = max($min, min($max, (int) $values[$key]));
            }
        }
        foreach (['auth_password_enabled', 'auth_google_enabled', 'auth_password_rehash', 'auth_google_link_by_email', 'auth_google_signup'] as $bool) {
            if (array_key_exists($bool, $values)) {
                $values[$bool] = filter_var($values[$bool], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['auth_users_identity_columns', 'auth_users_claim_columns', 'auth_users_hidden_columns', 'auth_google_client_ids', 'auth_google_hosted_domains', 'auth_password_legacy'] as $list) {
            if (isset($values[$list]) && is_string($values[$list])) {
                $values[$list] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $values[$list]) ?: [])));
            }
        }
        if (isset($values['auth_password_legacy'])) {
            $values['auth_password_legacy'] = array_values(array_intersect((array) $values['auth_password_legacy'], array_keys(PasswordHasher::LEGACY)));
        }
        if (isset($values['auth_google_signup_fields']) && is_string($values['auth_google_signup_fields'])) {
            // "name=name, avatar=picture" (column=claim)
            $fields = [];
            foreach (array_filter(array_map('trim', explode(',', $values['auth_google_signup_fields']))) as $pair) {
                [$column, $claim] = array_map('trim', explode('=', $pair, 2)) + [1 => ''];
                if ($column === '' || $claim === '') {
                    throw ValidationException::forField('auth_google_signup_fields', 'Write column=claim pairs, e.g. name=name, avatar=picture.');
                }
                $fields[$column] = $claim;
            }
            $values['auth_google_signup_fields'] = $fields;
        }

        // Other settings pages never fail because of the users table.
        $touchesLogin = array_filter(array_keys($values), static fn (string $k): bool => str_starts_with($k, 'auth_') && $k !== 'auth_mode') !== [];
        $merged = $values + $store->settings();
        if (! $touchesLogin || (! $merged['auth_password_enabled'] && ! $merged['auth_google_enabled'])) {
            return $values;
        }
        if ($merged['auth_google_enabled'] && (array) $merged['auth_google_client_ids'] === []) {
            throw ValidationException::forField('auth_google_client_ids', 'Google sign-in needs at least one OAuth client id.');
        }
        $table = $this->api->schema()->find((string) $merged['auth_users_table'], (string) $merged['auth_users_connection'] ?: null);
        if ($table === null) {
            throw ValidationException::forField('auth_users_table', 'Table "' . $merged['auth_users_table'] . '" not found.');
        }
        $columns = [
            'auth_users_id_column' => [$merged['auth_users_id_column']],
            'auth_users_password_column' => [$merged['auth_users_password_column']],
            'auth_users_identity_columns' => (array) $merged['auth_users_identity_columns'],
            'auth_users_role_column' => array_filter([$merged['auth_users_role_column']]),
            'auth_users_active_column' => array_filter([$merged['auth_users_active_column']]),
            'auth_users_claim_columns' => (array) $merged['auth_users_claim_columns'],
        ];
        if ($merged['auth_google_enabled']) {
            $columns['auth_google_email_column'] = array_filter([$merged['auth_google_email_column']]);
            $columns['auth_google_id_column'] = array_filter([$merged['auth_google_id_column']]);
            $columns['auth_google_signup_fields'] = array_keys((array) $merged['auth_google_signup_fields']);
        }
        foreach ($columns as $key => $names) {
            if ($names === [] && in_array($key, ['auth_users_id_column', 'auth_users_password_column', 'auth_users_identity_columns'], true)) {
                throw ValidationException::forField($key, 'Required.');
            }
            foreach ($names as $name) {
                if (! $table->hasColumn((string) $name)) {
                    throw ValidationException::forField($key, 'Column "' . $name . '" not found in "' . $table->name . '".');
                }
            }
        }
        // The default "jwt" strategy needs a secret: create one instead of failing at the first login.
        if ($merged['auth_token_strategy'] === 'jwt' && (string) ($merged['jwt_secret'] ?? '') === '' && (string) $this->api->config()->get('jwtSecret', '') === '') {
            $values['jwt_secret'] = bin2hex(random_bytes(32));
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function settingsSave(array $input): array
    {
        $editable = array_diff(array_keys(ConfigStore::SETTING_DEFAULTS), ['setup_completed', 'framework', 'default_connection']);
        $values = array_intersect_key($input, array_flip($editable));

        if (isset($values['auth_mode']) && ! in_array($values['auth_mode'], Authenticator::MODES, true)) {
            throw ValidationException::forField('auth_mode', 'Invalid authentication mode.');
        }
        if (array_key_exists('jwt_secret', $values) && ($values['jwt_secret'] === '' || $values['jwt_secret'] === null)) {
            unset($values['jwt_secret']); // empty means "keep the current secret"
        } elseif (isset($values['jwt_secret']) && strlen((string) $values['jwt_secret']) < 32) {
            throw ValidationException::forField('jwt_secret', 'The JWT secret must be at least 32 characters.');
        }
        if (isset($values['jwt_algorithm']) && ! in_array($values['jwt_algorithm'], ['HS256', 'HS384', 'HS512'], true)) {
            throw ValidationException::forField('jwt_algorithm', 'Unsupported algorithm.');
        }
        if (isset($values['log_requests']) && ! in_array($values['log_requests'], ['all', 'errors', 'none'], true)) {
            throw ValidationException::forField('log_requests', 'Use all, errors or none.');
        }
        foreach (['default_per_page', 'max_limit', 'schema_cache_ttl', 'cors_max_age', 'rate_limit_requests', 'rate_limit_anon_requests', 'log_retention_days',
            'orphan_min_age_hours', 'storage_trash_days'] as $int) {
            if (isset($values[$int])) {
                $values[$int] = max(0, (int) $values[$int]);
            }
        }
        if (isset($values['rate_limit_window'])) {
            $values['rate_limit_window'] = max(1, (int) $values['rate_limit_window']);
        }
        foreach (['auto_expose', 'allow_query_apikey', 'cors_enabled', 'response_envelope', 'delete_replaced_files', 'expose_openapi',
            'rate_limit_enabled', 'rate_limit_exempt_service', 'log_admin', 'jwt_require_exp', 'jwt_allow_service_role', 'storage_auto_cleanup'] as $bool) {
            if (array_key_exists($bool, $values)) {
                $values[$bool] = filter_var($values[$bool], FILTER_VALIDATE_BOOLEAN);
            }
        }
        foreach (['auto_exclude_tables', 'cors_origins'] as $list) {
            if (isset($values[$list]) && is_string($values[$list])) {
                $values[$list] = array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $values[$list]) ?: [])));
            }
        }
        if (isset($values['upload_max_size'])) {
            \GroceryRestable\Support\Size::toBytes((string) $values['upload_max_size']);
        }
        if (isset($values['default_permissions'])) {
            $resource = (new Resource('validation', 'validation'))->setPermissions((array) $values['default_permissions']);
            $values['default_permissions'] = $resource->getPermissions();
        }
        $values = $this->userAuthSettings($values);

        $this->api->store()->saveSettings($values);
        $this->api->store()->clearCache();
        $this->api->registry()->reload();

        return $this->settingsGet();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function userUpdate(array $body, array $user): array
    {
        $id = (int) ($body['id'] ?? 0);
        if ($id === (int) $user['id']) {
            throw ApiException::badRequest('Change your own account in "My account" (it asks for your current password).');
        }
        if (isset($body['username']) && $body['username'] !== '') {
            $this->api->store()->changeUsername($id, (string) $body['username']);
        }
        if (isset($body['password']) && $body['password'] !== '') {
            $this->api->store()->changePassword($id, (string) $body['password']);
        }

        return ['ok' => true];
    }

    /**
     * Change the username and / or password of the logged in admin (current password required).
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function accountUpdate(array $body, array $user): array
    {
        $id = (int) $user['id'];
        if (! $this->api->store()->checkPassword($id, (string) ($body['current_password'] ?? ''))) {
            throw ValidationException::forField('current_password', 'The current password is wrong.');
        }
        $username = trim((string) ($body['username'] ?? ''));
        if ($username !== '' && $username !== $user['username']) {
            $this->api->store()->changeUsername($id, $username);
        }
        $password = (string) ($body['password'] ?? '');
        if ($password !== '') {
            if ($password !== (string) ($body['password_confirm'] ?? '')) {
                throw ValidationException::forField('password_confirm', 'The passwords do not match.');
            }
            $this->api->store()->changePassword($id, $password);
            // A new session id after a password change; other sessions expire with the idle timeout.
            $this->session->regenerate();
        }

        return ['ok' => true, 'username' => $username !== '' ? $username : $user['username']];
    }

    /**
     * What an admin action is about, for the admin log (never a secret).
     *
     * @param array<string, mixed> $body
     */
    private function actionTarget(array $body): ?string
    {
        foreach (['name', 'table', 'bucket', 'day', 'id', 'username'] as $key) {
            if (isset($body[$key]) && is_scalar($body[$key])) {
                return mb_substr((string) $body[$key], 0, 100);
            }
        }
        $definition = $body['definition'] ?? null;

        return is_array($definition) && isset($definition['name']) && is_scalar($definition['name']) ? (string) $definition['name'] : null;
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function userDelete(array $body, array $user): array
    {
        $id = (int) ($body['id'] ?? 0);
        if ($id === (int) $user['id']) {
            throw ApiException::badRequest('You cannot delete your own account.');
        }
        $this->api->store()->deleteUser($id);

        return ['ok' => true];
    }

    /**
     * Run an API request as a chosen role and return the raw response.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function console(array $body): array
    {
        $method = strtoupper((string) ($body['method'] ?? 'GET'));
        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            throw ApiException::badRequest('Unsupported method.');
        }
        $role = (string) ($body['role'] ?? AuthContext::SERVICE_ROLE);
        ConfigStore::assertRole($role);
        $auth = match ($role) {
            AuthContext::SERVICE_ROLE => AuthContext::service('admin'),
            AuthContext::ANON => AuthContext::anonymous(),
            default => new AuthContext($role, 'admin'),
        };

        $path = (string) ($body['path'] ?? '');
        $query = '';
        if (str_contains($path, '?')) {
            [$path, $query] = explode('?', $path, 2);
        }
        $rawBody = (string) ($body['body'] ?? '');
        $request = new Request(
            $method,
            $path,
            $query,
            ['content-type' => 'application/json', 'prefer' => (string) ($body['prefer'] ?? '')],
            $rawBody,
            [],
            [],
            '127.0.0.1',
            $this->api->config()->apiUrl(),
        );

        $started = hrtime(true);
        $response = $this->api->api()->handle($request, null, $auth);

        return [
            'status' => $response->getStatus(),
            'headers' => $response->getHeaders(),
            'body' => $response->getFilePath() !== null ? '[file ' . basename($response->getFilePath()) . ']' : $response->getBody(),
            'durationMs' => round((hrtime(true) - $started) / 1e6, 2),
        ];
    }

    /**
     * Issue a test JWT signed with the configured secret.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function jwtGenerate(array $body): array
    {
        $store = $this->api->store();
        $secret = (string) $this->api->config()->get('jwtSecret', '') ?: (string) $store->setting('jwt_secret', '');
        if ($secret === '') {
            throw ApiException::badRequest('Configure a JWT secret first (Settings → Authentication).');
        }
        $role = (string) ($body['role'] ?? 'authenticated');
        ConfigStore::assertRole($role);
        $claims = [
            (string) $store->setting('jwt_role_claim', 'role') => $role,
            'sub' => (string) ($body['sub'] ?? 'admin-test'),
            'iat' => time(),
            'exp' => time() + max(60, min(86400 * 30, (int) ($body['expiresIn'] ?? 3600))),
        ];
        if ($store->setting('jwt_issuer')) {
            $claims['iss'] = $store->setting('jwt_issuer');
        }
        if ($store->setting('jwt_audience')) {
            $claims['aud'] = $store->setting('jwt_audience');
        }
        if (str_contains(array_key_first($claims), '.')) {
            throw ApiException::badRequest('Nested role claims cannot be generated here.');
        }

        return ['token' => Jwt::encode($claims, $secret, (string) $store->setting('jwt_algorithm', 'HS256')), 'claims' => $claims];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function import(array $data): array
    {
        $store = $this->api->store();
        $imported = ['resources' => 0, 'buckets' => 0, 'settings' => 0];
        foreach ((array) ($data['resources'] ?? []) as $name => $row) {
            $definition = (array) ($row['definition'] ?? []);
            $resource = Resource::fromArray(['name' => (string) $name] + $definition);
            $store->saveResource((string) $name, $resource->toArray(), (bool) ($row['enabled'] ?? true));
            $imported['resources']++;
        }
        foreach ((array) ($data['buckets'] ?? []) as $name => $bucket) {
            $store->saveBucket((string) $name, StorageService::normalizeDefinition((array) $bucket));
            $imported['buckets']++;
        }
        if (isset($data['settings']) && is_array($data['settings'])) {
            $settings = array_intersect_key($data['settings'], ConfigStore::SETTING_DEFAULTS);
            unset($settings['setup_completed'], $settings['jwt_secret']);
            $this->settingsSave($settings);
            $imported['settings'] = count($settings);
        }
        $this->api->registry()->reload();

        return $imported;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function connectionName(array $body): string
    {
        $name = (string) ($body['connection'] ?? '');

        return $name !== '' ? $name : $this->api->connections()->defaultName();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function table(array $body): Table
    {
        return $this->api->schema()->find((string) ($body['table'] ?? ''), $this->connectionName($body))
            ?? throw ApiException::notFound('Unknown table.');
    }

    private function done(mixed $ignored = null): array
    {
        return ['ok' => true];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentUser(): ?array
    {
        $id = $this->session->get('user_id');
        if (! is_int($id)) {
            return null;
        }
        if (time() - (int) $this->session->get('last_activity') > self::IDLE_TIMEOUT) {
            $this->session->remove('user_id');

            return null;
        }
        foreach ($this->api->store()->users() as $user) {
            if ((int) $user['id'] === $id) {
                $this->session->set('last_activity', time());

                return $user;
            }
        }
        $this->session->remove('user_id');

        return null;
    }

    private function csrfToken(): string
    {
        $token = $this->session->get('csrf');
        if (! is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf', $token);
        }

        return $token;
    }

    private function validCsrf(string $token): bool
    {
        $expected = $this->session->get('csrf');

        return is_string($expected) && $expected !== '' && hash_equals($expected, $token);
    }

    private function html(string $html, int $status = 200): Response
    {
        return Response::html($html, $status);
    }

    private function secure(Response $response): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            'Cache-Control' => $response->getHeader('Cache-Control') ?? 'no-store',
        ];
        if (str_starts_with((string) $response->getHeader('Content-Type'), 'text/html')) {
            $headers['Content-Security-Policy'] = "default-src 'self'; script-src 'self' https://cdn.jsdelivr.net; "
                . "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net; font-src 'self' https://cdn.jsdelivr.net data:; "
                . "img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'none'; form-action 'self'; base-uri 'none'; object-src 'none'";
        }

        return $response->withHeaders($headers);
    }
}
