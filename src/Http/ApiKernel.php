<?php

declare(strict_types=1);

namespace GroceryRestable\Http;

use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Exception\DatabaseException;
use GroceryRestable\Exception\ValidationException;
use GroceryRestable\GroceryRestable;
use GroceryRestable\Logging\LogStore;
use GroceryRestable\Query\QueryParser;
use GroceryRestable\Query\SelectNode;
use GroceryRestable\Resource\ResolvedResource;
use GroceryRestable\Resource\Resource;
use GroceryRestable\Resource\ResourceRegistry;
use GroceryRestable\Service\ActionContext;
use GroceryRestable\Service\StorageService;
use PDOException;
use Throwable;

/**
 * Routes REST requests to the services.
 *
 *   GET    /                          index of resources
 *   GET    /openapi.json              OpenAPI 3.1 document
 *   GET    /sdk.js                    JavaScript SDK (ES module)
 *   GET    /_schema[/{resource}]      field metadata (labels, types, relations) for UI builders
 *   GET    /{resource}                list (filters, select, order, paging, count, csv)
 *   POST   /{resource}                create one / many, upsert
 *   PATCH  /{resource}?filters        bulk update
 *   DELETE /{resource}?filters        bulk delete
 *   GET    /{resource}/_options/{f}   value/label pairs of a relation
 *   GET    /{resource}/{id}           read
 *   PUT    /{resource}/{id}           replace
 *   PATCH  /{resource}/{id}           update
 *   DELETE /{resource}/{id}           delete
 *   POST   /{resource}/{id}/clone     clone
 *   POST   /{resource}/{id}/upload/{field}    upload a file into a field
 *   DELETE /{resource}/{id}/upload/{field}    remove the file of a field
 *   GET    /{resource}/{id}/file/{field}      download the file of a field
 *   POST   /{resource}/{id}/actions/{name}    custom action
 *   *      /storage[/{bucket}[/{path}]]       storage buckets
 *   *      /rpc/{function}                    remote procedures
 *   GET    /auth                              sign-in methods
 *   POST   /auth/login | google | refresh | logout,  GET /auth/me    user login (UserAuth)
 */
final class ApiKernel
{
    private const SDK_FILE = __DIR__ . '/../../sdk/js/src/grocery-restable.js';

    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy' => 'no-referrer',
        'X-Frame-Options' => 'DENY',
    ];

    /** True while handling a request for a single mounted resource (Grocery CRUD style). */
    private bool $mounted = false;

    /** @var array<string, string> Rate limit headers of the current request. */
    private array $limitHeaders = [];

    /** @var array<string, string>|null Error of the current request, for the log. */
    private ?array $lastError = null;

    public function __construct(private readonly GroceryRestable $api)
    {
    }

    /**
     * @param Resource|null $resource When given, the request path is relative to this resource
     *                                (Grocery CRUD style "one controller method per table").
     * @param AuthContext|null $auth Skip authentication and act as this caller (admin console, tests).
     */
    public function handle(Request $request, ?Resource $resource = null, ?AuthContext $auth = null): Response
    {
        $started = hrtime(true);
        $cors = $this->corsHeaders($request);

        if ($request->method() === 'OPTIONS') {
            return Response::noContent(204, $cors + ['Allow' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS']);
        }

        $this->mounted = $resource !== null;
        $this->limitHeaders = [];
        $this->lastError = null;
        $internal = $auth !== null; // admin console and tests act as a given caller
        $target = null;
        try {
            $auth ??= $this->isSignInRoute($request, $resource) ? $this->authenticateOrAnonymous($request) : $this->authenticate($request);
            $target = $resource ?? $this->targetResource($request);
            if (! $internal) {
                $this->rateLimit($request, $auth, $target);
            }
            $response = $resource !== null
                ? $this->resourceRoute($resource, $request->segments(), $request, $auth)
                : $this->route($request, $auth);
        } catch (Throwable $e) {
            $response = $this->error($e, $request);
        }

        foreach (self::SECURITY_HEADERS as $name => $value) {
            if ($response->getHeader($name) === null) {
                $response = $response->withHeader($name, $value);
            }
        }
        $response = $response->withHeaders($cors + $this->limitHeaders);
        $this->logRequest($request, $response, $auth, $target, $started, $internal);

        return $response;
    }

    /**
     * Failed authentications also count against the rate limit of the client IP (key / token guessing).
     */
    private function authenticate(Request $request): AuthContext
    {
        try {
            return $this->api->authenticator()->authenticate($request);
        } catch (ApiException $e) {
            $this->rateLimit($request, AuthContext::anonymous(), null);
            throw $e;
        }
    }

    /**
     * Sign-in endpoints work with a stale or invalid token in the request (a client signing in again).
     */
    private function isSignInRoute(Request $request, ?Resource $resource): bool
    {
        $segments = $request->segments();

        return $resource === null && ($segments[0] ?? null) === 'auth' && in_array($segments[1] ?? '', ['', 'login', 'google', 'refresh'], true);
    }

    private function authenticateOrAnonymous(Request $request): AuthContext
    {
        try {
            return $this->authenticate($request);
        } catch (ApiException) {
            return AuthContext::anonymous();
        }
    }

    /**
     * The resource addressed by the first path segment (for per-resource limits and logs).
     */
    private function targetResource(Request $request): ?Resource
    {
        $first = $request->segments()[0] ?? null;
        if ($first === null || in_array($first, ResourceRegistry::RESERVED, true)) {
            return null;
        }

        return $this->api->registry()->find($first);
    }

    private function rateLimit(Request $request, AuthContext $auth, ?Resource $resource): void
    {
        $result = $this->api->rateLimiter()->hit($auth, $request->clientIp(), $resource);
        if ($result === null) {
            return;
        }
        $this->limitHeaders = [
            'X-RateLimit-Limit' => (string) $result['limit'],
            'X-RateLimit-Remaining' => (string) $result['remaining'],
            'X-RateLimit-Reset' => (string) $result['reset'],
        ];
        if (! $result['allowed']) {
            $retry = max(1, $result['reset']);
            $this->limitHeaders['Retry-After'] = (string) $retry;
            throw new ApiException('Too many requests. Try again in ' . $retry . ' seconds.', 429, 'rate_limited');
        }
    }

    private function logRequest(Request $request, Response $response, ?AuthContext $auth, ?Resource $target, int $started, bool $internal): void
    {
        try {
            $user = $auth?->userId();
            $this->api->logs()->request(array_filter([
                'method' => $request->method(),
                'path' => '/' . ltrim($request->path(), '/'),
                'query' => LogStore::redactQuery($request->queryString()),
                'status' => $response->getStatus(),
                'duration_ms' => round((hrtime(true) - $started) / 1e6, 2),
                'ip' => $request->clientIp(),
                'role' => $auth?->role,
                'auth' => $auth?->method,
                'key_id' => $auth?->apiKeyId,
                'user' => is_scalar($user) ? (string) $user : null,
                'resource' => $target?->getName(),
                'via' => $internal ? 'console' : 'api',
                'user_agent' => mb_substr((string) $request->header('user-agent'), 0, 200),
                'error' => $this->lastError,
            ], static fn (mixed $v): bool => $v !== null && $v !== ''));
        } catch (Throwable) {
            // logging must never break a response
        }
    }

    private function route(Request $request, AuthContext $auth): Response
    {
        $segments = $request->segments();
        $method = $request->method();

        if ($segments === []) {
            $this->allowMethods($method, ['GET']);

            return Response::json($this->index($request, $auth));
        }

        switch ($segments[0]) {
            case 'openapi.json':
                $this->allowMethods($method, ['GET']);
                if (! $this->api->store()->setting('expose_openapi', true) && ! $auth->isService()) {
                    throw ApiException::notFound();
                }

                return Response::json($this->api->openApi($request->baseUrl(), $auth));

            case 'sdk.js':
                // The JavaScript SDK, so browsers can `import GroceryRestable from '{api}/sdk.js'`.
                $this->allowMethods($method, ['GET']);

                return Response::text((string) file_get_contents(self::SDK_FILE), 'application/javascript; charset=utf-8', 200, ['Cache-Control' => 'public, max-age=3600']);

            case '_schema':
                $this->allowMethods($method, ['GET']);
                if (isset($segments[1])) {
                    $resource = $this->api->registry()->find($segments[1]) ?? throw ApiException::notFound('Unknown resource "' . $segments[1] . '".');

                    return Response::json($this->describe($this->api->registry()->resolve($resource), $auth));
                }
                $result = [];
                foreach ($this->api->registry()->all() as $name => $resource) {
                    if ($this->api->authenticator()->allows($auth, $resource, 'select')) {
                        try {
                            $result[$name] = $this->describe($this->api->registry()->resolve($resource), $auth);
                        } catch (ConfigurationException) {
                            continue;
                        }
                    }
                }

                return Response::json($result);

            case 'storage':
                return $this->storageRoute(array_slice($segments, 1), $request, $auth);

            case 'auth':
                // Tokens and user data must never be cached by browsers or proxies.
                return $this->authRoute(array_slice($segments, 1), $request, $auth)->withHeader('Cache-Control', 'no-store');

            case 'rpc':
                if (count($segments) !== 2) {
                    throw ApiException::notFound();
                }

                return $this->rpc($segments[1], $request, $auth);
        }

        $resource = $this->api->registry()->find($segments[0]) ?? throw ApiException::notFound('Unknown resource "' . $segments[0] . '".');

        return $this->resourceRoute($resource, array_slice($segments, 1), $request, $auth);
    }

    /**
     * @param list<string> $rest Path segments after the resource name.
     */
    private function resourceRoute(Resource $resource, array $rest, Request $request, AuthContext $auth): Response
    {
        $ctx = $this->api->registry()->resolve($resource);
        $method = $request->method();
        $count = count($rest);
        $reader = $this->api->reader();
        $writer = $this->api->writer();
        $parser = new QueryParser();
        $prefer = QueryParser::parsePrefer($request->header('prefer'));

        if ($count === 0) {
            switch ($method) {
                case 'GET':
                case 'HEAD':
                    return $this->list($ctx, $request, $auth, $prefer);
                case 'POST':
                    $isList = $request->isJson() && array_is_list($request->body()) && $request->body() !== [];
                    $ids = $writer->create($ctx, $request, $auth, ($prefer['resolution'] ?? null) === 'merge-duplicates');

                    return $this->writeResponse($ctx, $ids, $request, $auth, $prefer, 201, ! $isList);
                case 'PATCH':
                    $ids = $writer->updateMany($ctx, $parser->parse($request->queryPairs())->filters, $request, $auth);

                    return $this->writeResponse($ctx, $ids, $request, $auth, $prefer, 200, false);
                case 'DELETE':
                    $ids = $writer->deleteMany($ctx, $parser->parse($request->queryPairs())->filters, $auth);

                    return ($prefer['return'] ?? null) === 'minimal' ? Response::noContent() : Response::json(['deleted' => $ids]);
            }
            throw ApiException::methodNotAllowed();
        }

        if ($rest[0] === '_options' && $count === 2) {
            $this->allowMethods($method, ['GET']);
            $limit = min((int) ($request->queryParam('limit') ?? 50) ?: 50, (int) $this->api->store()->setting('max_limit', 1000));

            return Response::json($reader->options($ctx, $rest[1], $request->queryParam('q'), $limit, $auth));
        }
        if ($rest[0] === '_schema' && $count === 1) {
            $this->allowMethods($method, ['GET']);

            return Response::json($this->describe($ctx, $auth));
        }

        $id = $rest[0];

        if ($count === 1) {
            switch ($method) {
                case 'GET':
                case 'HEAD':
                    $select = $this->selectFor($request);
                    $row = $reader->find($ctx, $id, $select, $auth) ?? throw ApiException::notFound();

                    return Response::json($row);
                case 'PUT':
                case 'PATCH':
                    $writer->update($ctx, $id, $request, $auth, $method === 'PUT');

                    return $this->writeResponse($ctx, [$id], $request, $auth, $prefer, 200, true);
                case 'DELETE':
                    $writer->delete($ctx, $id, $auth);

                    return Response::noContent();
            }
            throw ApiException::methodNotAllowed();
        }

        if ($count === 2 && $rest[1] === 'clone') {
            $this->allowMethods($method, ['POST']);
            $newId = $writer->clone($ctx, $id, $request, $auth);

            return $this->writeResponse($ctx, [$newId], $request, $auth, $prefer, 201, true);
        }

        if ($count === 3 && $rest[1] === 'upload') {
            if ($method === 'POST' || $method === 'PUT') {
                $writer->uploadToField($ctx, $id, $rest[2], $request, $auth);
            } elseif ($method === 'DELETE') {
                $writer->removeFieldFile($ctx, $id, $rest[2], $auth);
            } else {
                throw ApiException::methodNotAllowed();
            }

            return $this->writeResponse($ctx, [$id], $request, $auth, $prefer, 200, true);
        }

        if ($count === 3 && $rest[1] === 'file') {
            $this->allowMethods($method, ['GET', 'HEAD']);
            $file = $writer->fieldFile($ctx, $id, $rest[2], $auth);

            return Response::file($file['path'], $file['name'], $file['mime'], $request->queryParam('download') === null);
        }

        if ($count === 3 && $rest[1] === 'actions') {
            $this->allowMethods($method, ['POST']);

            return $this->action($ctx, $id, $rest[2], $request, $auth);
        }

        throw ApiException::notFound();
    }

    /**
     * @param array<string, string> $prefer
     */
    private function list(ResolvedResource $ctx, Request $request, AuthContext $auth, array $prefer): Response
    {
        $options = (new QueryParser())->parse($request->queryPairs(), $prefer);
        if (str_contains((string) $request->header('accept'), 'text/csv')) {
            $options->format = 'csv';
        }
        if ($options->format === 'csv' && ! $ctx->resource->isEnabled('export')) {
            throw ApiException::methodNotAllowed('Export is disabled for "' . $ctx->name() . '".');
        }

        $result = $this->api->reader()->list($ctx, $options, $auth);
        $rows = $result['rows'];
        $count = count($rows);
        $range = $count === 0 ? '*' : $result['offset'] . '-' . ($result['offset'] + $count - 1);
        $headers = ['Content-Range' => $range . '/' . ($result['total'] ?? '*')];
        if ($result['total'] !== null) {
            $headers['X-Total-Count'] = (string) $result['total'];
        }

        if ($options->format === 'csv') {
            return Response::text($this->csv($rows), 'text/csv; charset=utf-8', 200, $headers + [
                'Content-Disposition' => 'attachment; filename="' . $ctx->name() . '.csv"',
            ]);
        }

        if ($this->api->store()->setting('response_envelope')) {
            return Response::json([
                'data' => $rows,
                'meta' => [
                    'total' => $result['total'],
                    'count' => $count,
                    'limit' => $result['limit'],
                    'offset' => $result['offset'],
                    'page' => $result['limit'] > 0 ? intdiv($result['offset'], $result['limit']) + 1 : 1,
                ],
            ], 200, $headers);
        }

        return Response::json($rows, 200, $headers);
    }

    /**
     * Response of a write operation honoring "Prefer: return=minimal|representation".
     *
     * @param list<int|string|null> $ids
     * @param array<string, string> $prefer
     */
    private function writeResponse(ResolvedResource $ctx, array $ids, Request $request, AuthContext $auth, array $prefer, int $status, bool $single): Response
    {
        $headers = [];
        $pk = $ctx->primaryKey();
        if ($single && $status === 201 && $pk !== null && isset($ids[0])) {
            $collection = $this->mounted ? $request->baseUrl() : $request->baseUrl() . '/' . rawurlencode($ctx->name());
            $headers['Location'] = $collection . '/' . rawurlencode((string) $ids[0]);
        }
        if (($prefer['return'] ?? null) === 'minimal') {
            return Response::noContent($status === 201 ? 201 : 204, $headers);
        }

        $canRead = $pk !== null && $ctx->resource->isEnabled('read') && $this->api->authenticator()->allows($auth, $ctx->resource, 'select');
        $select = $this->selectFor($request);
        $rows = [];
        foreach ($ids as $id) {
            $row = $canRead && $id !== null ? $this->api->reader()->find($ctx, $id, $select, $auth) : null;
            $rows[] = $row ?? ($pk !== null ? [$pk => $id] : []);
        }

        return Response::json($single ? ($rows[0] ?? null) : $rows, $status, $headers);
    }

    private function selectFor(Request $request): SelectNode
    {
        $select = $request->queryParam('select');
        if ($select === null) {
            return new SelectNode();
        }
        $node = (new QueryParser())->parseSelect($select);
        $node->explicit = true;

        return $node;
    }

    private function action(ResolvedResource $ctx, string $id, string $name, Request $request, AuthContext $auth): Response
    {
        $action = $ctx->resource->getActions()[$name] ?? throw ApiException::notFound('Unknown action "' . $name . '".');
        $this->api->authenticator()->authorize($auth, $ctx->resource, $action['permission']);
        $row = $this->api->reader()->findRaw($ctx, $id, $auth) ?? throw ApiException::notFound();
        $result = ($action['callback'])($row[$ctx->requirePrimaryKey()], $request->body(), new ActionContext($auth, $request, $ctx->db, $ctx));

        return $this->toResponse($result);
    }

    private function rpc(string $name, Request $request, AuthContext $auth): Response
    {
        $function = $this->api->functions()[$name] ?? throw ApiException::notFound('Unknown function "' . $name . '".');
        $this->allowMethods($request->method(), $function['methods']);

        $roles = $function['roles'];
        if (! $auth->isService() && ! in_array('*', $roles, true) && ! in_array($auth->role, $roles, true)) {
            throw $auth->isAnonymous() ? ApiException::unauthorized() : ApiException::forbidden();
        }

        $params = [];
        if ($request->method() === 'GET') {
            foreach ($request->queryPairs() as [$key, $value]) {
                if ($key !== 'apikey') {
                    $params[$key] = $value;
                }
            }
        } else {
            $params = $request->body();
        }

        return $this->toResponse(($function['callback'])($params, new ActionContext($auth, $request, $this->api->connections()->get($function['connection'] ?? null))));
    }

    /**
     * User login (see UserAuth).
     *
     * @param list<string> $rest
     */
    private function authRoute(array $rest, Request $request, AuthContext $auth): Response
    {
        $users = $this->api->auth();
        $method = $request->method();

        switch (implode('/', $rest)) {
            case '':
                $this->allowMethods($method, ['GET']);

                return Response::json($users->methods());
            case 'login':
                $this->allowMethods($method, ['POST']);

                return Response::json($users->login($request));
            case 'google':
                $this->allowMethods($method, ['POST']);

                return Response::json($users->google($request));
            case 'refresh':
                $this->allowMethods($method, ['POST']);

                return Response::json($users->refresh($request));
            case 'logout':
                $this->allowMethods($method, ['POST']);
                $users->logout($request, $auth);

                return Response::noContent();
            case 'me':
                $this->allowMethods($method, ['GET']);

                return Response::json($users->me($auth));
        }

        throw ApiException::notFound();
    }

    /**
     * @param list<string> $rest
     */
    private function storageRoute(array $rest, Request $request, AuthContext $auth): Response
    {
        $storage = $this->api->storage();
        $method = $request->method();

        if ($rest === []) {
            $this->allowMethods($method, ['GET']);

            return Response::json($storage->listBuckets($auth));
        }

        $bucket = $rest[0];
        $path = implode('/', array_slice($rest, 1));

        $objectUrl = fn (string $key): string => $request->baseUrl() . '/storage/' . rawurlencode($bucket) . '/' . implode('/', array_map('rawurlencode', explode('/', $key)));

        if ($path === '') {
            if ($method === 'GET') {
                // ListObjectsV2: prefix, delimiter ("/" or "" for a recursive listing), limit, cursor.
                return Response::json($storage->listObjects(
                    $bucket,
                    (string) $request->queryParam('prefix'),
                    $auth,
                    $request->queryParam('delimiter') ?? '/',
                    (int) ($request->queryParam('limit') ?? StorageService::MAX_LIST),
                    (string) $request->queryParam('cursor'),
                ));
            }
            if ($method === 'POST' || $method === 'PUT') {
                $file = $request->file('file') ?? throw ValidationException::forField('file', 'Send the file in a multipart "file" field.');
                $body = $request->body();
                $object = $storage->upload(
                    $bucket,
                    $file,
                    (string) ($body['path'] ?? $request->queryParam('path') ?? ''),
                    isset($body['name']) ? (string) $body['name'] : $request->queryParam('name'),
                    $method === 'PUT' || filter_var($body['upsert'] ?? $request->queryParam('upsert') ?? false, FILTER_VALIDATE_BOOLEAN),
                    $auth,
                    ['cacheControl' => $body['cache_control'] ?? $request->header('x-cache-control'), 'metadata' => $body['metadata'] ?? null],
                );
                $this->api->janitor()->routine();

                return Response::json($object + ['url' => $objectUrl($object['path'])], $object['replaced'] ? 200 : 201);
            }
            throw ApiException::methodNotAllowed();
        }

        if (($method === 'GET' || $method === 'HEAD') && $request->queryParam('info') !== null) {
            $info = $storage->info($bucket, $path, $auth);

            return Response::json($info + ['url' => $objectUrl($info['path'])]);
        }
        if ($method === 'GET' || $method === 'HEAD') {
            $file = $storage->download($bucket, $path, $auth);
            $headers = [
                'ETag' => '"' . $file['etag'] . '"',
                'Last-Modified' => gmdate('D, d M Y H:i:s', (int) strtotime((string) $file['last_modified'])) . ' GMT',
                'Cache-Control' => $file['cache_control'],
            ];
            $ifNoneMatch = (string) $request->header('if-none-match');
            if ($ifNoneMatch !== '' && in_array($headers['ETag'], array_map('trim', explode(',', str_replace('W/', '', $ifNoneMatch))), true)) {
                return Response::noContent(304, $headers);
            }
            if ($method === 'HEAD') {
                return Response::noContent(200, $headers + ['Content-Type' => $file['mime'], 'Content-Length' => (string) $file['size']]);
            }

            return Response::file($file['path'], $file['download_name'], $file['mime'], $request->queryParam('download') === null)->withHeaders($headers);
        }
        if ($method === 'DELETE') {
            $storage->delete($bucket, $path, $auth);
            $this->api->janitor()->routine();

            return Response::noContent();
        }
        throw ApiException::methodNotAllowed();
    }

    /**
     * Field metadata so clients can build forms (labels, types, required, options...).
     *
     * @return array<string, mixed>
     */
    private function describe(ResolvedResource $ctx, AuthContext $auth): array
    {
        $resource = $ctx->resource;
        $table = $ctx->table;
        $authenticator = $this->api->authenticator();
        if (! $authenticator->allows($auth, $resource, 'select') && ! $authenticator->allows($auth, $resource, 'insert')) {
            throw $auth->isAnonymous() ? ApiException::unauthorized() : ApiException::forbidden();
        }

        $createFields = $resource->writableFields($table, 'create');
        $updateFields = $resource->writableFields($table, 'update');
        $fields = [];
        foreach (array_unique([...$resource->queryableColumns($table), ...$createFields, ...$updateFields]) as $name) {
            $column = $table->column($name);
            $relation = $resource->getRelationByField($name) ?? $resource->getRelation($name);
            $override = $resource->getFieldType($name);
            $fields[$name] = array_filter([
                'label' => $resource->label($name),
                'type' => $override['type'] ?? $column?->type ?? ($relation?->isManyToMany() ? 'relation' : 'string'),
                'nativeType' => $column?->nativeType,
                'nullable' => $column?->nullable,
                'maxLength' => $column?->maxLength,
                'default' => $column?->default,
                'primaryKey' => $name === $ctx->primaryKey() ?: null,
                'required' => in_array($name, $resource->getRequiredFields(), true) || ($column !== null && in_array($name, $createFields, true) && ! $column->isOptionalOnInsert()),
                'unique' => in_array($name, $resource->getUniqueFields(), true) ?: null,
                'readable' => in_array($name, $resource->queryableColumns($table), true),
                'creatable' => in_array($name, $createFields, true),
                'updatable' => in_array($name, $updateFields, true),
                'options' => $override !== null && is_array($override['extras']) ? $override['extras'] : ($column?->enumValues ?: null),
                'rules' => $resource->getRules()[$name]['rules'] ?? null,
                'upload' => $resource->getUpload($name)?->toArray(),
                'relation' => $relation !== null ? ['name' => $relation->name, 'type' => $relation->type, 'table' => $relation->table, 'options' => ($relation->isHasMany() ? null : '_options/' . $name)] : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        $permissions = [];
        foreach (Resource::PERMISSIONS as $permission) {
            $permissions[$permission] = $authenticator->allows($auth, $resource, $permission);
        }

        return [
            'name' => $ctx->name(),
            'subject' => $resource->getSubject(),
            'subjectPlural' => $resource->getSubjectPlural(),
            'description' => $resource->getDescription(),
            'primaryKey' => $ctx->primaryKey(),
            'operations' => $resource->getOperations(),
            'permissions' => $permissions,
            'listColumns' => $resource->listColumns($table),
            'readColumns' => $resource->readColumns($table),
            'searchable' => $resource->searchableColumns($table),
            'fields' => $fields,
            'relations' => array_map(static fn ($r): array => $r->toArray(), array_values($resource->getRelations())),
            'actions' => array_keys($resource->getActions()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function index(Request $request, AuthContext $auth): array
    {
        $resources = [];
        foreach ($this->api->registry()->all() as $name => $resource) {
            if (! $auth->isService() && ! $this->api->authenticator()->allows($auth, $resource, 'select') && ! $this->api->authenticator()->allows($auth, $resource, 'insert')) {
                continue;
            }
            $resources[] = [
                'name' => $name,
                'subject' => $resource->getSubject(),
                'url' => $request->baseUrl() . '/' . rawurlencode($name),
            ];
        }

        return [
            'name' => $this->api->store()->setting('app_name', 'Grocery Restable'),
            'role' => $auth->role,
            'resources' => $resources,
            'openapi' => $this->api->store()->setting('expose_openapi', true) ? $request->baseUrl() . '/openapi.json' : null,
            'storage' => $request->baseUrl() . '/storage',
        ];
    }

    private function toResponse(mixed $result): Response
    {
        return match (true) {
            $result instanceof Response => $result,
            $result === null => Response::noContent(),
            default => Response::json($result),
        };
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $key) {
                $columns[$key] = true;
            }
        }
        $columns = array_keys($columns);
        fputcsv($handle, $columns, ',', '"', '');
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? null;
                $value = match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? 'true' : 'false',
                    is_array($value) => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    default => (string) $value,
                };
                // Neutralize spreadsheet formula injection.
                if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
                    $value = "'" . $value;
                }
                $line[] = $value;
            }
            fputcsv($handle, $line, ',', '"', '');
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return "\u{FEFF}" . $csv;
    }

    /**
     * @return array<string, string>
     */
    private function corsHeaders(Request $request): array
    {
        $store = $this->api->store();
        if (! $store->setting('cors_enabled', true)) {
            return [];
        }
        $origin = $request->header('origin');
        $allowed = (array) $store->setting('cors_origins', ['*']);
        $headers = [
            'Access-Control-Allow-Methods' => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers' => 'apikey, authorization, content-type, prefer, range, x-api-key, x-http-method-override, x-requested-with',
            'Access-Control-Expose-Headers' => 'Content-Range, X-Total-Count, Location, Content-Disposition, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Retry-After',
            'Access-Control-Max-Age' => (string) (int) $store->setting('cors_max_age', 600),
        ];
        if (in_array('*', $allowed, true)) {
            return ['Access-Control-Allow-Origin' => '*'] + $headers;
        }
        if ($origin !== null && in_array(rtrim($origin, '/'), array_map(static fn (string $o): string => rtrim($o, '/'), $allowed), true)) {
            return ['Access-Control-Allow-Origin' => $origin, 'Vary' => 'Origin'] + $headers;
        }

        return ['Vary' => 'Origin'];
    }

    /**
     * @param list<string> $allowed
     */
    private function allowMethods(string $method, array $allowed): void
    {
        if ($method === 'HEAD' && in_array('GET', $allowed, true)) {
            return;
        }
        if (! in_array($method, array_map('strtoupper', $allowed), true)) {
            throw ApiException::methodNotAllowed();
        }
    }

    private function error(Throwable $e, Request $request): Response
    {
        $debug = $this->api->config()->isDebug();
        $details = [];

        if ($e instanceof ApiException) {
            $status = $e->getStatus();
            $code = $e->getErrorCode();
            $message = $e->getMessage();
            $details = $e->getDetails();
        } elseif ($e instanceof PDOException || $e instanceof DatabaseException) {
            $state = $e instanceof DatabaseException ? $e->getSqlState() : (string) ($e->errorInfo[0] ?? $e->getCode());
            // MySQL reports "field has no default value" (1364) with the generic SQLSTATE HY000.
            if ($state === 'HY000' && $e instanceof PDOException && (int) ($e->errorInfo[1] ?? 0) === 1364) {
                $state = '23000';
            }
            [$status, $code, $message] = match (true) {
                str_starts_with($state, '23') => [409, 'constraint_violation', 'The operation violates a database constraint (duplicate value or invalid reference).'],
                str_starts_with($state, '22') => [400, 'invalid_value', 'A value is invalid for its database column.'],
                default => [500, 'database_error', 'A database error occurred.'],
            };
        } elseif ($e instanceof ConfigurationException) {
            [$status, $code, $message] = [500, 'configuration_error', 'Server configuration error.'];
        } else {
            [$status, $code, $message] = [500, 'internal_error', 'Internal server error.'];
        }

        if ($status >= 500 || ! $e instanceof ApiException) {
            $this->api->log('error', 'Grocery Restable: ' . $e->getMessage(), ['exception' => $e, 'path' => $request->path()]);
        }
        $this->lastError = ['code' => $code, 'message' => $message]
            + (! $e instanceof ApiException ? ['exception' => $e::class, 'detail' => mb_substr($e->getMessage(), 0, 500)] : []);

        $payload = ['error' => array_filter(['code' => $code, 'message' => $message, 'details' => $details ?: null], static fn (mixed $v): bool => $v !== null)];
        if ($debug && ! $e instanceof ApiException) {
            $payload['error']['debug'] = [
                'exception' => $e::class,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
            ];
        }

        $response = Response::json($payload, $status);
        if ($status === 401) {
            $response = $response->withHeader('WWW-Authenticate', 'Bearer realm="grocery-restable"');
        }
        if ($status === 429 && is_int($details['retry_after'] ?? null)) {
            $response = $response->withHeader('Retry-After', (string) $details['retry_after']);
        }

        return $response;
    }
}
