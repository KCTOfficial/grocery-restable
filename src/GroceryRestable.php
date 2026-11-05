<?php

declare(strict_types=1);

namespace GroceryRestable;

use GroceryRestable\Admin\AdminKernel;
use GroceryRestable\Admin\NativeSession;
use GroceryRestable\Admin\SessionInterface;
use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Auth\Authenticator;
use GroceryRestable\Auth\UserAuth;
use GroceryRestable\Database\ConnectionInterface;
use GroceryRestable\Database\ConnectionManager;
use GroceryRestable\Database\Schema\SchemaRepository;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Http\ApiKernel;
use GroceryRestable\Http\RateLimiter;
use GroceryRestable\Http\Request;
use GroceryRestable\Http\Response;
use GroceryRestable\Logging\LogStore;
use GroceryRestable\OpenApi\OpenApiGenerator;
use GroceryRestable\Resource\Resource;
use GroceryRestable\Resource\ResourceRegistry;
use GroceryRestable\Service\ResourceReader;
use GroceryRestable\Service\ResourceWriter;
use GroceryRestable\Service\StorageJanitor;
use GroceryRestable\Service\StorageService;
use GroceryRestable\Service\UploadService;
use GroceryRestable\Store\ConfigStore;
use GroceryRestable\Support\Crypto;
use GroceryRestable\Validation\Validator;
use GroceryRestable\Validation\ValidatorInterface;

/**
 * The Grocery Restable engine. Framework integrations create one instance per application.
 *
 *   $api = GroceryRestable::create(['storagePath' => __DIR__ . '/writable/grocery-restable']);
 *
 *   $api->resource('employees')
 *       ->setRelation('officeCode', 'offices', 'city')
 *       ->requiredFields('lastName');
 *
 *   $response = $api->handle($request);   // GroceryRestable\Http\Request -> Response
 */
final class GroceryRestable
{
    public const VERSION = '1.2.0';

    private ?ConfigStore $store = null;
    private ?ConnectionManager $connections = null;
    private ?SchemaRepository $schema = null;
    private ?ResourceRegistry $registry = null;
    private ?Authenticator $authenticator = null;
    private ?UserAuth $userAuth = null;
    private ?ValidatorInterface $validator = null;
    private ?UploadService $uploads = null;
    private ?StorageService $storage = null;
    private ?ResourceReader $reader = null;
    private ?ResourceWriter $writer = null;
    private ?ApiKernel $apiKernel = null;
    private ?LogStore $logs = null;
    private ?RateLimiter $rateLimiter = null;
    private ?StorageJanitor $janitor = null;
    private ?AdminKernel $adminKernel = null;

    /** @var array<string, array{callback: callable, methods: list<string>, roles: list<string>, description: string, connection: string|null}> */
    private array $functions = [];

    /** @var list<callable(self): void> */
    private array $bootCallbacks = [];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * @param array<string, mixed> $config See {@see Config::DEFAULTS}.
     */
    public static function create(array $config): self
    {
        return new self(new Config($config));
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function store(): ConfigStore
    {
        return $this->store ??= new ConfigStore(
            $this->config->configDatabase(),
            Crypto::fromConfig((string) $this->config->get('encryptionKey', ''), $this->config->storagePath() . DIRECTORY_SEPARATOR . 'grocery-restable.key'),
        );
    }

    public function connections(): ConnectionManager
    {
        return $this->connections ??= new ConnectionManager($this->store());
    }

    /**
     * Register a framework / application provided database connection.
     *
     * @param callable(): ConnectionInterface $factory
     * @param array<string, mixed> $meta
     */
    public function addConnection(string $name, callable $factory, array $meta = []): self
    {
        $this->connections()->addProvider($name, $factory, $meta);

        return $this;
    }

    public function schema(): SchemaRepository
    {
        return $this->schema ??= new SchemaRepository($this->connections(), $this->store());
    }

    public function registry(): ResourceRegistry
    {
        $this->boot();

        return $this->registry ??= new ResourceRegistry($this->store(), $this->connections(), $this->schema());
    }

    public function authenticator(): Authenticator
    {
        return $this->authenticator ??= new Authenticator($this->store(), (string) $this->config->get('jwtSecret', ''));
    }

    /**
     * User login for the API (/auth): password login on your users table, Google sign-in, tokens and hooks.
     */
    public function auth(): UserAuth
    {
        // Hooks are usually registered in booting() callbacks (e.g. CodeIgniter's define()): run them first.
        $this->boot();

        return $this->userAuth ??= new UserAuth($this, (string) $this->config->get('jwtSecret', ''));
    }

    public function validator(): ValidatorInterface
    {
        return $this->validator ??= new Validator();
    }

    public function setValidator(ValidatorInterface $validator): self
    {
        $this->validator = $validator;
        $this->writer = null;

        return $this;
    }

    public function uploads(): UploadService
    {
        return $this->uploads ??= new UploadService($this->config, $this->store());
    }

    public function storage(): StorageService
    {
        $this->boot();

        return $this->storage ??= new StorageService($this->config, $this->store(), $this->uploads());
    }

    public function reader(): ResourceReader
    {
        return $this->reader ??= new ResourceReader(
            $this->registry(),
            $this->schema(),
            $this->authenticator(),
            $this->store(),
            maxEmbedDepth: (int) $this->config->get('maxEmbedDepth', 3),
        );
    }

    public function writer(): ResourceWriter
    {
        return $this->writer ??= new ResourceWriter(
            $this->reader(),
            $this->schema(),
            $this->authenticator(),
            $this->validator(),
            $this->uploads(),
            $this->store(),
            (int) $this->config->get('maxBulkRows', 1000),
            fn (string $password): string => $this->auth()->hashPassword($password),
        );
    }

    /**
     * JSON request / admin logs, one file per day.
     */
    public function logs(): LogStore
    {
        return $this->logs ??= new LogStore($this->config->logPath(), $this->store());
    }

    /**
     * Orphan file detection and storage housekeeping.
     */
    public function janitor(): StorageJanitor
    {
        return $this->janitor ??= new StorageJanitor($this);
    }

    public function rateLimiter(): RateLimiter
    {
        return $this->rateLimiter ??= RateLimiter::create($this->store());
    }

    public function api(): ApiKernel
    {
        return $this->apiKernel ??= new ApiKernel($this);
    }

    public function admin(): AdminKernel
    {
        if (! $this->config->get('adminEnabled', true)) {
            throw new ConfigurationException('The Grocery Restable admin GUI is disabled.');
        }

        return $this->adminKernel ??= new AdminKernel($this);
    }

    // ------------------------------------------------------------------
    // Definition API
    // ------------------------------------------------------------------

    /**
     * Define (or get) a code resource. The table defaults to the name.
     */
    public function resource(string $name, ?string $table = null): Resource
    {
        $existing = $this->registry()->codeResources()[$name] ?? null;
        if ($existing !== null) {
            if ($table !== null) {
                $existing->setTable($table);
            }

            return $existing;
        }

        return $this->registry()->register(new Resource($name, $table ?? $name));
    }

    public function register(Resource $resource): Resource
    {
        return $this->registry()->register($resource);
    }

    /**
     * Define a storage bucket in code. See {@see StorageService} for the options.
     *
     * @param array<string, mixed> $definition
     */
    public function bucket(string $name, array $definition = []): self
    {
        $this->storage()->register($name, StorageService::normalizeDefinition($definition));

        return $this;
    }

    /**
     * Register a remote procedure: /rpc/{name}
     *
     *   $api->rpc('stats', fn (array $params, ActionContext $ctx) => [...], ['methods' => ['GET'], 'roles' => ['authenticated']]);
     *
     * Options: methods (default ["POST"]), roles (default ["authenticated"], "*" = everybody),
     *          description, connection.
     *
     * @param callable(array<string, mixed>, \GroceryRestable\Service\ActionContext): mixed $callback
     * @param array<string, mixed> $options
     */
    public function rpc(string $name, callable $callback, array $options = []): self
    {
        ConfigStore::assertName($name, 'function');
        $this->functions[$name] = [
            'callback' => $callback,
            'methods' => array_map('strtoupper', (array) ($options['methods'] ?? ['POST'])),
            'roles' => array_values((array) ($options['roles'] ?? ['authenticated'])),
            'description' => (string) ($options['description'] ?? ''),
            'connection' => isset($options['connection']) ? (string) $options['connection'] : null,
        ];

        return $this;
    }

    /**
     * @return array<string, array{callback: callable, methods: list<string>, roles: list<string>, description: string, connection: string|null}>
     */
    public function functions(): array
    {
        $this->boot();

        return $this->functions;
    }

    /**
     * Run $callback lazily the first time resources are needed (keeps bootstrapping cheap).
     *
     * @param callable(self): void $callback
     */
    public function booting(callable $callback): self
    {
        $this->bootCallbacks[] = $callback;

        return $this;
    }

    // ------------------------------------------------------------------
    // Request handling
    // ------------------------------------------------------------------

    /**
     * Handle a REST API request (path relative to the API base URL).
     */
    public function handle(Request $request): Response
    {
        return $this->api()->handle($request);
    }

    /**
     * Handle a request for a single resource (path relative to the resource), Grocery CRUD style.
     */
    public function render(Resource $resource, Request $request): Response
    {
        return $this->api()->handle($request, $resource);
    }

    /**
     * Handle an admin GUI request (path relative to the admin base URL).
     */
    public function handleAdmin(Request $request, ?SessionInterface $session = null): Response
    {
        $session ??= new NativeSession();
        $response = $this->admin()->handle($request, $session);
        if ($session instanceof NativeSession) {
            // Persist the session before the response is sent, otherwise a fast follow-up
            // request can arrive before the session file exists and receive a new session id.
            $session->close();
        }

        return $response;
    }

    /**
     * OpenAPI document. With $auth, only what that caller can use is described
     * (resources with at least one permission, readable buckets, callable functions).
     *
     * @return array<string, mixed>
     */
    public function openApi(string $serverUrl, ?AuthContext $auth = null): array
    {
        $auth ??= AuthContext::service();
        $authenticator = $this->authenticator();
        $resolved = [];
        foreach ($this->registry()->all() as $resource) {
            $usable = $auth->isService() || array_filter(Resource::PERMISSIONS, static fn (string $p): bool => $authenticator->allows($auth, $resource, $p)) !== [];
            if (! $usable) {
                continue;
            }
            try {
                $resolved[] = $this->registry()->resolve($resource);
            } catch (ConfigurationException) {
                continue;
            }
        }

        $functions = array_filter(
            $this->functions(),
            static fn (array $f): bool => $auth->isService() || in_array('*', $f['roles'], true) || in_array($auth->role, $f['roles'], true),
        );

        return (new OpenApiGenerator())->generate(
            $resolved,
            $this->storage()->listBuckets($auth),
            array_map(static fn (array $f): array => array_diff_key($f, ['callback' => true]), $functions),
            $this->store()->settings(),
            $serverUrl,
        );
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $logger = $this->config->get('logger');
        if (is_callable($logger)) {
            $logger($level, $message, $context);
        } elseif ($logger instanceof \Psr\Log\LoggerInterface) {
            $logger->log($level, $message, $context);
        } else {
            error_log($message);
        }
    }

    private function boot(): void
    {
        if ($this->bootCallbacks === []) {
            return;
        }
        $callbacks = $this->bootCallbacks;
        $this->bootCallbacks = [];
        foreach ($callbacks as $callback) {
            $callback($this);
        }
    }
}
