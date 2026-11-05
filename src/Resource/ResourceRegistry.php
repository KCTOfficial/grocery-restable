<?php

declare(strict_types=1);

namespace GroceryRestable\Resource;

use GroceryRestable\Database\ConnectionManager;
use GroceryRestable\Database\Grammar\Grammar;
use GroceryRestable\Database\Schema\SchemaRepository;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Store\ConfigStore;

/**
 * Finds resource definitions. Precedence: code > GUI (SQLite) > auto (schema).
 */
final class ResourceRegistry
{
    /** Names that cannot be used for resources because they are API routes. */
    public const RESERVED = ['rpc', 'storage', '_schema', 'openapi', 'auth'];

    /** @var array<string, Resource> */
    private array $code = [];

    /** @var array<string, Resource>|null */
    private ?array $stored = null;

    /** @var array<string, ResolvedResource> */
    private array $resolved = [];

    public function __construct(
        private readonly ConfigStore $store,
        private readonly ConnectionManager $connections,
        private readonly SchemaRepository $schema,
        private readonly AutoResourceFactory $auto = new AutoResourceFactory(),
    ) {
    }

    public function register(Resource $resource): Resource
    {
        $name = $resource->getName();
        if ($name === '' || $resource->getTable() === '') {
            throw new ConfigurationException('A resource needs a name and a table.');
        }
        if (in_array(strtolower($name), self::RESERVED, true)) {
            throw new ConfigurationException('"' . $name . '" is a reserved name.');
        }
        $this->code[$name] = $resource->setSource('code');
        unset($this->resolved[$name]);

        return $resource;
    }

    /**
     * @return array<string, Resource>
     */
    public function codeResources(): array
    {
        return $this->code;
    }

    /**
     * @return array<string, Resource>
     */
    public function storedResources(): array
    {
        if ($this->stored === null) {
            $this->stored = [];
            foreach ($this->store->resources() as $name => $row) {
                if (! $row['enabled'] || isset($this->code[$name])) {
                    continue;
                }
                try {
                    $this->stored[$name] = Resource::fromArray(['name' => $name] + $row['definition'])->setSource('gui');
                } catch (ConfigurationException) {
                    // A broken stored definition must not take the whole API down; the GUI reports it.
                    continue;
                }
            }
        }

        return $this->stored;
    }

    public function find(string $name): ?Resource
    {
        if (in_array(strtolower($name), self::RESERVED, true)) {
            return null;
        }

        return $this->code[$name] ?? $this->storedResources()[$name] ?? $this->autoResource($name);
    }

    /**
     * Every exposed resource.
     *
     * @return array<string, Resource>
     */
    public function all(): array
    {
        $all = $this->code + $this->storedResources();
        if ($this->autoExposeEnabled()) {
            foreach ($this->autoTableNames() as $table) {
                if (! isset($all[$table]) && ($resource = $this->autoResource($table)) !== null) {
                    $all[$table] = $resource;
                }
            }
        }
        ksort($all);

        return $all;
    }

    /**
     * True when $table was deliberately kept out of the API: listed in auto_exclude_tables, or
     * configured in the GUI with its resource disabled. Relations must not reach such tables.
     */
    public function isWithheld(string $table, ?string $connection = null): bool
    {
        $connection ??= $this->connections->defaultName();
        $isDefault = $connection === $this->connections->defaultName();
        $excluded = array_map('strtolower', (array) $this->store->setting('auto_exclude_tables', []));
        if ($isDefault && in_array(strtolower($table), $excluded, true)) {
            return true;
        }
        foreach ($this->store->resources() as $name => $row) {
            $definition = $row['definition'];
            $sameTable = ($definition['table'] ?? $name) === $table;
            $sameConnection = (($definition['connection'] ?? null) ?? $this->connections->defaultName()) === $connection;
            if (! $row['enabled'] && $sameTable && $sameConnection && ! isset($this->code[$name])) {
                return true;
            }
        }

        return false;
    }

    /**
     * The resource exposing $table (used for permissions and visibility of embedded rows).
     */
    public function forTable(string $table, ?string $connection = null): ?Resource
    {
        $connection ??= $this->connections->defaultName();

        $candidates = [];
        foreach ($this->code + $this->storedResources() as $resource) {
            $resourceConnection = $resource->getConnection() ?? $this->connections->defaultName();
            if ($resource->getTable() === $table && $resourceConnection === $connection) {
                $candidates[$resource->getName()] = $resource;
            }
        }
        if (isset($candidates[$table])) {
            return $candidates[$table];
        }
        if ($candidates !== []) {
            return reset($candidates);
        }
        if ($connection === $this->connections->defaultName()) {
            return $this->autoResource($table);
        }

        return null;
    }

    public function resolve(Resource $resource): ResolvedResource
    {
        $key = spl_object_id($resource) . ':' . $resource->getName();
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $connectionName = $resource->getConnection() ?? $this->connections->defaultName();
        $table = $this->schema->find($resource->getTable(), $connectionName);
        if ($table === null) {
            throw new ConfigurationException(sprintf('Table "%s" of resource "%s" does not exist on connection "%s".', $resource->getTable(), $resource->getName(), $connectionName));
        }
        $db = $this->connections->get($connectionName);

        return $this->resolved[$key] = new ResolvedResource($resource, $table, $db, Grammar::for($db->driver()), $connectionName);
    }

    /**
     * Build an auto resource for a table even when auto expose is off (GUI "generate").
     */
    public function generate(string $table, ?string $connection = null): Resource
    {
        $tables = $this->schema->tables($connection);
        if (! isset($tables[$table])) {
            throw new ConfigurationException('Table "' . $table . '" does not exist.');
        }
        $resource = $this->auto->make($tables[$table], $tables, $connection === $this->connections->defaultName() ? null : $connection);
        if (! preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $table)) {
            $resource->setName((string) preg_replace('/[^A-Za-z0-9_-]/', '_', $table));
        }

        return $resource;
    }

    public function reload(): void
    {
        $this->stored = null;
        $this->resolved = [];
    }

    public function autoExposeEnabled(): bool
    {
        return (bool) $this->store->setting('auto_expose', false);
    }

    private function autoResource(string $name): ?Resource
    {
        if (! $this->autoExposeEnabled() || ! in_array($name, $this->autoTableNames(), true)) {
            return null;
        }
        $tables = $this->schema->tables();

        return $this->auto->make($tables[$name], $tables);
    }

    /**
     * @return list<string>
     */
    private function autoTableNames(): array
    {
        try {
            $tables = $this->schema->tables();
        } catch (\Throwable) {
            return [];
        }
        $excluded = array_map('strtolower', (array) $this->store->setting('auto_exclude_tables', []));
        $configuredTables = [];
        foreach ($this->code + $this->storedResources() as $resource) {
            if ($resource->getConnection() === null || $resource->getConnection() === $this->connections->defaultName()) {
                $configuredTables[$resource->getTable()] = true;
            }
        }

        $names = [];
        foreach (array_keys($tables) as $table) {
            if (in_array(strtolower($table), $excluded, true)
                || isset($configuredTables[$table])
                || in_array(strtolower($table), self::RESERVED, true)
                || ! preg_match('/^[A-Za-z_][A-Za-z0-9_-]{0,63}$/', $table)) {
                continue;
            }
            $names[] = $table;
        }

        return $names;
    }
}
