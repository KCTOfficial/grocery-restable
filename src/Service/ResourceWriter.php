<?php

declare(strict_types=1);

namespace GroceryRestable\Service;

use DateTimeImmutable;
use GroceryRestable\Auth\AuthContext;
use GroceryRestable\Auth\Authenticator;
use GroceryRestable\Database\Schema\Column;
use GroceryRestable\Database\Schema\SchemaRepository;
use GroceryRestable\Exception\ApiException;
use GroceryRestable\Exception\ConfigurationException;
use GroceryRestable\Exception\ValidationException;
use GroceryRestable\Http\Request;
use GroceryRestable\Http\UploadedFileInterface;
use GroceryRestable\Query\FilterGroup;
use GroceryRestable\Resource\Relation;
use GroceryRestable\Resource\ResolvedResource;
use GroceryRestable\Resource\Resource;
use GroceryRestable\Store\ConfigStore;
use GroceryRestable\Support\Uuid;
use GroceryRestable\Validation\ValidatorInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Write operations: create, update, delete, clone, bulk operations and field uploads.
 */
final class ResourceWriter
{
    public function __construct(
        private readonly ResourceReader $reader,
        private readonly SchemaRepository $schema,
        private readonly Authenticator $auth,
        private readonly ValidatorInterface $validator,
        private readonly UploadService $uploads,
        private readonly ConfigStore $store,
        private readonly int $maxBulkRows = 1000,
        /** Hashes "password" fields; defaults to password_hash() with PASSWORD_DEFAULT. */
        private readonly ?\Closure $passwordHasher = null,
    ) {
    }

    /**
     * Insert one record (JSON object / form) or many (JSON array).
     * With $upsert, rows whose primary key already exists are updated instead.
     *
     * @return list<int|string|null> Primary keys of the created (or upserted) rows.
     */
    public function create(ResolvedResource $ctx, Request $request, AuthContext $auth, bool $upsert = false): array
    {
        $this->assertEnabled($ctx, 'create');
        $this->auth->authorize($auth, $ctx->resource, 'insert');

        $body = $request->body();
        $isList = array_is_list($body) && $body !== [];
        $rows = $isList ? $body : [$body];
        if (count($rows) > $this->maxBulkRows) {
            throw ApiException::badRequest('At most ' . $this->maxBulkRows . ' rows can be inserted at once.');
        }
        if ($isList && $request->files() !== []) {
            throw ApiException::badRequest('Files can only be uploaded when creating a single record.');
        }

        $pk = $ctx->primaryKey();
        $stored = [];

        try {
            return $ctx->db->transaction(function () use ($ctx, $request, $auth, $rows, $upsert, $pk, &$stored): array {
                $ids = [];
                foreach ($rows as $index => $row) {
                    if (! is_array($row) || array_is_list($row) && $row !== []) {
                        throw ApiException::badRequest('Each row must be a JSON object.');
                    }
                    if ($upsert && $pk !== null && isset($row[$pk]) && $this->reader->findRaw($ctx, $row[$pk], $auth) !== null) {
                        $this->assertEnabled($ctx, 'update');
                        $this->auth->authorize($auth, $ctx->resource, 'update');
                        $this->updateRow($ctx, $row[$pk], $row, [], $request->isFormBody(), $auth, false, $stored);
                        $ids[] = $row[$pk];
                        continue;
                    }
                    try {
                        $ids[] = $this->insertRow($ctx, $row, $index === 0 ? $request->files() : [], $request->isFormBody(), $auth, $stored);
                    } catch (ValidationException $e) {
                        throw count($rows) > 1 ? new ValidationException($e->getErrors(), 'Row ' . $index . ' is invalid.') : $e;
                    }
                }

                return $ids;
            });
        } catch (Throwable $e) {
            $this->discard($stored);
            throw $e;
        }
    }

    /**
     * Update a record. $replace (PUT) requires every required field.
     */
    public function update(ResolvedResource $ctx, int|string $id, Request $request, AuthContext $auth, bool $replace = false): void
    {
        $this->assertEnabled($ctx, 'update');
        $this->auth->authorize($auth, $ctx->resource, 'update');
        $body = $request->body();
        if (array_is_list($body) && $body !== []) {
            throw ApiException::badRequest('The body must be a JSON object.');
        }

        $stored = [];
        try {
            $ctx->db->transaction(function () use ($ctx, $id, $body, $request, $auth, $replace, &$stored): void {
                $this->updateRow($ctx, $id, $body, $request->files(), $request->isFormBody(), $auth, $replace, $stored);
            });
        } catch (Throwable $e) {
            $this->discard($stored);
            throw $e;
        }
    }

    /**
     * PATCH /resource?filters - apply the same changes to every matching row.
     *
     * @return list<int|string>
     */
    public function updateMany(ResolvedResource $ctx, FilterGroup $filters, Request $request, AuthContext $auth): array
    {
        $this->assertEnabled($ctx, 'update');
        $this->auth->authorize($auth, $ctx->resource, 'update');
        if ($request->files() !== []) {
            throw ApiException::badRequest('Files cannot be uploaded in bulk updates.');
        }
        $body = $request->body();
        $ids = $this->reader->matchingIds($ctx, $filters, $auth, $this->maxBulkRows);
        $stored = [];
        $ctx->db->transaction(function () use ($ctx, $ids, $body, $request, $auth, &$stored): void {
            foreach ($ids as $id) {
                $this->updateRow($ctx, $id, $body, [], $request->isFormBody(), $auth, false, $stored);
            }
        });

        return $ids;
    }

    public function delete(ResolvedResource $ctx, int|string $id, AuthContext $auth): void
    {
        $this->assertEnabled($ctx, 'delete');
        $this->auth->authorize($auth, $ctx->resource, 'delete');
        $files = $ctx->db->transaction(fn (): array => $this->deleteRow($ctx, $id, $auth));
        $this->removeFiles($ctx, $files);
    }

    /**
     * @return list<int|string>
     */
    public function deleteMany(ResolvedResource $ctx, FilterGroup $filters, AuthContext $auth): array
    {
        $this->assertEnabled($ctx, 'delete');
        $this->auth->authorize($auth, $ctx->resource, 'delete');
        $ids = $this->reader->matchingIds($ctx, $filters, $auth, $this->maxBulkRows);
        $files = $ctx->db->transaction(function () use ($ctx, $ids, $auth): array {
            $files = [];
            foreach ($ids as $id) {
                array_push($files, ...$this->deleteRow($ctx, $id, $auth));
            }

            return $files;
        });
        $this->removeFiles($ctx, $files);

        return $ids;
    }

    /**
     * Duplicate a record. The body may override fields (e.g. unique ones).
     */
    public function clone(ResolvedResource $ctx, int|string $id, Request $request, AuthContext $auth): int|string|null
    {
        $this->assertEnabled($ctx, 'clone');
        $this->auth->authorize($auth, $ctx->resource, 'select');
        $this->auth->authorize($auth, $ctx->resource, 'insert');

        $source = $this->reader->findRaw($ctx, $id, $auth) ?? throw ApiException::notFound();
        $pk = $ctx->requirePrimaryKey();
        $resource = $ctx->resource;

        $data = [];
        foreach ($source as $column => $value) {
            $definition = $ctx->table->column($column);
            // The primary key and files are never copied (a non generated key must be sent in the body).
            if ($definition === null || $definition->generated || $column === $pk || $resource->getUpload($column) !== null) {
                continue;
            }
            $data[$column] = $value;
        }
        foreach ($request->body() as $key => $value) {
            if (! $ctx->table->hasColumn((string) $key) || ! in_array($key, $resource->writableFields($ctx->table, 'create'), true)) {
                throw ValidationException::forField((string) $key, 'This field is not writable.');
            }
            $data[$key] = $this->normalize($resource, $ctx->table->columns[$key], $value, $request->isFormBody(), $ctx->db->driver());
        }
        if (! isset($data[$pk]) && ($generated = $this->newId($ctx)) !== null) {
            $data[$pk] = $generated;
        }
        $this->assertUnique($ctx, $data, null);

        return $ctx->db->transaction(function () use ($ctx, $id, $data, $auth, $pk): int|string|null {
            $data = $this->runBefore($ctx->resource, 'before_clone', [$data, $id, $auth]) ?? $data;
            $newId = $this->insertSql($ctx, $data);

            foreach ($ctx->resource->getRelations() as $relation) {
                if ($relation->isManyToMany()) {
                    $this->syncManyToMany($ctx, $relation, $newId, $this->currentRelationIds($ctx, $relation, $id));
                }
            }
            $this->assertInScope($ctx, $newId, $auth);
            $this->runAfter($ctx->resource, 'after_clone', [$data + [$pk => $newId], $newId, $auth]);

            return $newId;
        });
    }

    /**
     * POST /resource/{id}/upload/{field}
     */
    public function uploadToField(ResolvedResource $ctx, int|string $id, string $field, Request $request, AuthContext $auth): void
    {
        $this->assertEnabled($ctx, 'update');
        $this->auth->authorize($auth, $ctx->resource, 'update');
        $upload = $ctx->resource->getUpload($field) ?? throw ApiException::notFound('"' . $field . '" is not an upload field.');
        $this->assertFieldWritable($ctx, $field);
        $file = $request->file('file') ?? $request->file($field) ?? throw ValidationException::forField('file', 'Send the file in a multipart "file" field.');

        $existing = $this->reader->findRaw($ctx, $id, $auth) ?? throw ApiException::notFound();
        $stored = [];
        try {
            $ctx->db->transaction(function () use ($ctx, $id, $field, $upload, $file, $auth, &$stored): void {
                $value = $this->storeUpload($ctx->resource, $upload, $file, $auth, $stored);
                $ctx->db->execute(
                    'UPDATE ' . $ctx->wrappedTable() . ' SET ' . $ctx->grammar->wrap($field) . ' = ? WHERE ' . $ctx->grammar->wrap($ctx->requirePrimaryKey()) . ' = ?',
                    [$value, $id],
                );
            });
        } catch (Throwable $e) {
            $this->discard($stored);
            throw $e;
        }
        if ($this->store->setting('delete_replaced_files', true)) {
            $this->uploads->deleteFieldFile($upload, $existing[$field] ?? null);
        }
    }

    /**
     * DELETE /resource/{id}/upload/{field}
     */
    public function removeFieldFile(ResolvedResource $ctx, int|string $id, string $field, AuthContext $auth): void
    {
        $this->assertEnabled($ctx, 'update');
        $this->auth->authorize($auth, $ctx->resource, 'update');
        $upload = $ctx->resource->getUpload($field) ?? throw ApiException::notFound('"' . $field . '" is not an upload field.');
        $this->assertFieldWritable($ctx, $field);
        $existing = $this->reader->findRaw($ctx, $id, $auth) ?? throw ApiException::notFound();

        $ctx->db->execute(
            'UPDATE ' . $ctx->wrappedTable() . ' SET ' . $ctx->grammar->wrap($field) . ' = NULL WHERE ' . $ctx->grammar->wrap($ctx->requirePrimaryKey()) . ' = ?',
            [$id],
        );
        $this->uploads->deleteFieldFile($upload, $existing[$field] ?? null);
    }

    /**
     * Resolve the file of an upload field for download.
     *
     * @return array{path: string, name: string, mime: string}
     */
    public function fieldFile(ResolvedResource $ctx, int|string $id, string $field, AuthContext $auth): array
    {
        if (! $ctx->resource->isEnabled('read') && ! $ctx->resource->isEnabled('list')) {
            throw ApiException::methodNotAllowed();
        }
        $this->auth->authorize($auth, $ctx->resource, 'select');
        $upload = $ctx->resource->getUpload($field) ?? throw ApiException::notFound('"' . $field . '" is not an upload field.');
        if (! in_array($field, $ctx->resource->queryableColumns($ctx->table), true)) {
            throw ApiException::forbidden('The field "' . $field . '" is not readable.');
        }
        $row = $this->reader->findRaw($ctx, $id, $auth) ?? throw ApiException::notFound();
        $path = $this->uploads->fieldFilePath($upload, isset($row[$field]) ? (string) $row[$field] : null)
            ?? throw ApiException::notFound('No file stored for "' . $field . '".');

        $name = (string) $row[$field];
        $original = preg_replace('/^[0-9a-f]{8}-/', '', $name);

        return ['path' => $path, 'name' => (string) $original, 'mime' => $this->uploads->detectMime($path)];
    }

    // ------------------------------------------------------------------
    // Row level helpers
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $input
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files
     * @param list<array{0: \GroceryRestable\Resource\UploadField, 1: string}> $stored
     */
    private function insertRow(ResolvedResource $ctx, array $input, array $files, bool $isForm, AuthContext $auth, array &$stored): int|string|null
    {
        $resource = $ctx->resource;
        [$data, $relations, $uploadFiles] = $this->prepare($ctx, $input, $files, $isForm, 'create', null);

        foreach ($uploadFiles as $field => $file) {
            $data[$field] = $this->storeUpload($resource, $resource->getUpload($field), $file, $auth, $stored);
        }

        $data = $this->runBefore($resource, 'before_insert', [$data, $auth]) ?? $data;

        $replacement = $resource->getCallbacks('insert')[0] ?? null;
        $id = $replacement !== null ? $replacement($data, $auth) : $this->insertSql($ctx, $data);

        foreach ($relations as $name => $ids) {
            $this->syncManyToMany($ctx, $resource->getRelation($name), $id, $ids, $auth);
        }
        $this->assertInScope($ctx, $id, $auth);

        $pk = $ctx->primaryKey();
        $this->runAfter($resource, 'after_insert', [$pk !== null ? $data + [$pk => $id] : $data, $id, $auth]);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files
     * @param list<array{0: \GroceryRestable\Resource\UploadField, 1: string}> $stored
     */
    private function updateRow(ResolvedResource $ctx, int|string $id, array $input, array $files, bool $isForm, AuthContext $auth, bool $replace, array &$stored): void
    {
        $resource = $ctx->resource;
        $pk = $ctx->requirePrimaryKey();
        $existing = $this->reader->findRaw($ctx, $id, $auth) ?? throw ApiException::notFound();
        $id = $existing[$pk];

        [$data, $relations, $uploadFiles] = $this->prepare($ctx, $input, $files, $isForm, $replace ? 'replace' : 'update', $id);

        $replacedFiles = [];
        foreach ($uploadFiles as $field => $file) {
            $data[$field] = $this->storeUpload($resource, $resource->getUpload($field), $file, $auth, $stored);
        }
        foreach ($resource->getUploads() as $field => $upload) {
            if (array_key_exists($field, $data) && ($existing[$field] ?? null) !== null && $existing[$field] !== $data[$field]) {
                $replacedFiles[] = [$upload, (string) $existing[$field]];
            }
        }

        $data = $this->runBefore($resource, 'before_update', [$data, $id, $auth]) ?? $data;

        $replacement = $resource->getCallbacks('update')[0] ?? null;
        if ($replacement !== null) {
            $replacement($data, $id, $auth);
        } elseif ($data !== []) {
            $sets = [];
            foreach (array_keys($data) as $column) {
                $sets[] = $ctx->grammar->wrap($column) . ' = ?';
            }
            $ctx->db->execute(
                'UPDATE ' . $ctx->wrappedTable() . ' SET ' . implode(', ', $sets) . ' WHERE ' . $ctx->grammar->wrap($pk) . ' = ?',
                [...array_values($data), $id],
            );
        }

        foreach ($relations as $name => $ids) {
            $this->syncManyToMany($ctx, $resource->getRelation($name), $id, $ids, $auth);
        }
        $this->assertInScope($ctx, $id, $auth);

        $this->runAfter($resource, 'after_update', [$data, $id, $auth]);

        if ($replacedFiles !== [] && $this->store->setting('delete_replaced_files', true)) {
            foreach ($replacedFiles as [$upload, $file]) {
                $this->uploads->deleteFieldFile($upload, $file);
            }
        }
    }

    /**
     * @return list<array{0: \GroceryRestable\Resource\UploadField, 1: string}> Files to remove after commit.
     */
    private function deleteRow(ResolvedResource $ctx, int|string $id, AuthContext $auth): array
    {
        $resource = $ctx->resource;
        $pk = $ctx->requirePrimaryKey();
        $existing = $this->reader->findRaw($ctx, $id, $auth) ?? throw ApiException::notFound();
        $id = $existing[$pk];

        foreach ($resource->getCallbacks('before_delete') as $callback) {
            if ($callback($id, $auth) === false) {
                throw new ApiException('The delete operation was cancelled.', 400, 'cancelled');
            }
        }

        $replacement = $resource->getCallbacks('delete')[0] ?? null;
        if ($replacement !== null) {
            $replacement($id, $auth);
        } else {
            // Like Grocery CRUD, remove the junction rows of many_to_many relations first.
            foreach ($resource->getRelations() as $relation) {
                if ($relation->isManyToMany()) {
                    $ctx->db->execute(
                        'DELETE FROM ' . $ctx->grammar->wrapTable((string) $relation->junctionTable) . ' WHERE ' . $ctx->grammar->wrap((string) $relation->junctionThisKey) . ' = ?',
                        [$id],
                    );
                }
            }
            $ctx->db->execute('DELETE FROM ' . $ctx->wrappedTable() . ' WHERE ' . $ctx->grammar->wrap($pk) . ' = ?', [$id]);
        }

        $this->runAfter($resource, 'after_delete', [$id, $auth]);

        $files = [];
        if ($replacement === null) {
            foreach ($resource->getUploads() as $field => $upload) {
                if (($existing[$field] ?? null) !== null) {
                    $files[] = [$upload, (string) $existing[$field]];
                }
            }
        }

        return $files;
    }

    /**
     * Validate and normalize client input.
     *
     * @param array<string, mixed> $input
     * @param array<string, UploadedFileInterface|list<UploadedFileInterface>> $files
     * @param 'create'|'update'|'replace' $mode
     * @return array{0: array<string, mixed>, 1: array<string, list<mixed>>, 2: array<string, UploadedFileInterface>}
     */
    private function prepare(ResolvedResource $ctx, array $input, array $files, bool $isForm, string $mode, int|string|null $id): array
    {
        $resource = $ctx->resource;
        $table = $ctx->table;
        $writable = $resource->writableFields($table, $mode === 'create' ? 'create' : 'update');
        $pk = $ctx->primaryKey();
        $errors = [];
        $columns = [];
        $relations = [];
        $uploadFiles = [];

        foreach ($input as $key => $value) {
            $key = (string) $key;
            if ($mode !== 'create' && $key === $pk && ! in_array($pk, $writable, true)) {
                if ((string) $value !== (string) $id) {
                    $errors[$key] = 'The primary key cannot be changed.';
                }
                continue;
            }
            if (! in_array($key, $writable, true)) {
                $errors[$key] = $table->hasColumn($key) || $resource->getRelation($key) !== null ? 'This field is not writable.' : 'Unknown field.';
                continue;
            }
            $relation = $resource->getRelation($key);
            if ($relation !== null && $relation->isManyToMany()) {
                try {
                    $relations[$key] = $this->relationIds($value, $isForm);
                } catch (InvalidArgumentException $e) {
                    $errors[$key] = $e->getMessage();
                }
                continue;
            }
            if ($resource->getUpload($key) !== null && $value !== null && $value !== '') {
                $errors[$key] = 'Send files with multipart/form-data or POST {resource}/{id}/upload/' . $key . '.';
                continue;
            }
            $columns[$key] = $value;
        }

        foreach ($files as $field => $file) {
            $file = is_array($file) ? ($file[0] ?? null) : $file;
            if ($file === null || $file->getError() === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($resource->getUpload($field) === null || ! in_array($field, $writable, true)) {
                $errors[$field] = 'This field does not accept files.';
                continue;
            }
            $uploadFiles[$field] = $file;
            unset($columns[$field]);
        }

        $raw = $columns;
        foreach ($resource->forcedValues() as $field => $value) {
            if ($table->hasColumn($field)) {
                $columns[$field] = $value;
            }
        }

        if ($mode === 'create' && $pk !== null) {
            $sent = $columns[$pk] ?? null;
            if ($sent !== null && $sent !== '') {
                if (in_array($resource->getIdGenerator(), Uuid::GENERATORS, true) && ! Uuid::isValid((string) $sent)) {
                    $errors[$pk] = 'The ' . $resource->label($pk) . ' must be a UUID.';
                }
            } elseif (($generated = $this->newId($ctx)) !== null) {
                $columns[$pk] = $generated;
            }
        }

        $isEmpty = static fn (mixed $v): bool => $v === null || $v === '' || $v === [];
        $requiresAll = $mode !== 'update';
        foreach ($resource->getRequiredFields() as $field) {
            $present = array_key_exists($field, $columns) || isset($uploadFiles[$field]) || array_key_exists($field, $relations);
            $value = $columns[$field] ?? $relations[$field] ?? (isset($uploadFiles[$field]) ? 'file' : null);
            if (($requiresAll && (! $present || $isEmpty($value))) || ($present && $isEmpty($value))) {
                $errors[$field] ??= 'The ' . $resource->label($field) . ' field is required.';
            }
        }
        if ($requiresAll) {
            foreach ($writable as $field) {
                $column = $table->column($field);
                if ($column !== null && ! $column->isOptionalOnInsert() && ! array_key_exists($field, $columns) && ! isset($uploadFiles[$field])) {
                    $errors[$field] ??= 'The ' . $resource->label($field) . ' field is required.';
                }
            }
        }

        $driver = $ctx->db->driver();
        foreach ($columns as $field => $value) {
            if (isset($errors[$field])) {
                continue;
            }
            $type = $resource->getFieldType($field)['type'] ?? null;
            if ($type === 'password' && $mode !== 'create' && $isEmpty($value)) {
                unset($columns[$field]); // empty password on update means "unchanged"
                continue;
            }
            try {
                $columns[$field] = $this->normalize($resource, $table->columns[$field], $value, $isForm, $driver);
            } catch (InvalidArgumentException $e) {
                $errors[$field] = $e->getMessage();
            }
        }

        $rules = [];
        foreach ($resource->getRules() as $field => $definition) {
            if (in_array($field, $writable, true)) {
                $rules[$field] = ['rules' => $definition['rules'], 'label' => $definition['label'] ?? $resource->label($field)];
            }
        }
        if ($rules !== []) {
            $ruleInput = $raw + $relations;
            if ($mode === 'update') {
                // Partial update: only the fields that were sent are validated.
                $rules = array_intersect_key($rules, $ruleInput + $uploadFiles);
            } else {
                // On create / replace every rule runs, including "required" for absent fields.
                foreach (array_keys($rules) as $field) {
                    if (! array_key_exists($field, $ruleInput) && isset($uploadFiles[$field])) {
                        $ruleInput[$field] = $uploadFiles[$field]->getClientFilename();
                    }
                }
            }
            foreach ($this->validator->validate($ruleInput, $rules) as $field => $message) {
                $errors[$field] ??= $message;
            }
        }

        if ($errors === []) {
            try {
                $this->assertUnique($ctx, $columns, $id);
            } catch (ValidationException $e) {
                $errors += $e->getErrors();
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        foreach ($columns as $field => $value) {
            if (($resource->getFieldType($field)['type'] ?? null) === 'password' && is_string($value)) {
                $columns[$field] = $this->passwordHasher !== null ? ($this->passwordHasher)($value) : password_hash($value, PASSWORD_DEFAULT);
            }
        }

        return [$columns, $relations, $uploadFiles];
    }

    /**
     * Convert an input value to what the database expects, validating it against the column type.
     */
    public function normalize(Resource $resource, Column $column, mixed $value, bool $isForm, string $driver): mixed
    {
        $override = $resource->getFieldType($column->name);
        $type = $override['type'] ?? $column->type;
        $extras = $override['extras'] ?? null;
        $label = $resource->label($column->name);

        if ($isForm && $value === '' && ! in_array($type, ['string', 'text', 'password', 'hidden'], true)) {
            $value = null;
        }
        if ($value === null) {
            if (! $column->nullable && ! $column->autoIncrement) {
                throw new InvalidArgumentException('The ' . $label . ' field cannot be null.');
            }

            return null;
        }

        switch ($type) {
            case 'password':
                if (! is_string($value)) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be a string.');
                }

                return $value; // hashed after validation

            case 'boolean':
            case 'true_false':
                if (is_bool($value)) {
                    return $value;
                }
                $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($bool === null || is_array($value)) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be true or false.');
                }

                return $bool;

            case 'integer':
                if (is_int($value)) {
                    return $value;
                }
                if (is_bool($value) || ! is_scalar($value) || ! preg_match('/^[\-+]?\d+$/', trim((string) $value))) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be an integer.');
                }
                $value = trim((string) $value);

                return strlen(ltrim($value, '-+')) < 19 ? (int) $value : $value;

            case 'number':
                if (is_int($value) || is_float($value)) {
                    return $value;
                }
                if (is_bool($value) || ! is_scalar($value) || ! is_numeric(trim((string) $value))) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be a number.');
                }

                return trim((string) $value);

            case 'json':
                if (is_array($value)) {
                    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
                if (! is_string($value) || ! json_validate($value)) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be valid JSON.');
                }

                return $value;

            case 'set':
            case 'multiselect':
                $values = is_array($value) ? $value : ($value === '' ? [] : explode(',', (string) $value));
                $allowed = self::allowedValues($extras, $column);
                foreach ($values as $item) {
                    if (! is_scalar($item) || ($allowed !== [] && ! in_array((string) $item, $allowed, true))) {
                        throw new InvalidArgumentException('The ' . $label . ' field contains an invalid value.');
                    }
                }

                return implode(',', array_map('strval', $values));

            case 'enum':
            case 'dropdown':
                $allowed = self::allowedValues($extras, $column);
                if (! is_scalar($value) || ($allowed !== [] && ! in_array((string) $value, $allowed, true))) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be one of: ' . implode(', ', $allowed) . '.');
                }

                return is_bool($value) ? (int) $value : $value;

            case 'date':
            case 'datetime':
                if (! is_string($value)) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be a date string.');
                }
                try {
                    $date = new DateTimeImmutable($value);
                } catch (\Exception) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be a valid date.');
                }
                if ($type === 'date') {
                    return $date->format('Y-m-d');
                }

                // PostgreSQL understands ISO 8601 (with time zone); MySQL / SQLite expect "Y-m-d H:i:s".
                return $driver === 'pgsql' ? $value : $date->format('Y-m-d H:i:s');

            case 'time':
                if (! is_string($value) || ! preg_match('/^\d{1,2}:\d{2}(:\d{2}(\.\d+)?)?$/', $value)) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be a time (HH:MM[:SS]).');
                }

                return $value;

            default:
                if (is_array($value) || is_object($value)) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be a string.');
                }
                if (is_bool($value)) {
                    $value = (int) $value;
                }
                $string = (string) $value;
                if ($column->maxLength !== null && mb_strlen($string) > $column->maxLength) {
                    throw new InvalidArgumentException(sprintf('The %s field cannot exceed %d characters in length.', $label, $column->maxLength));
                }
                if ($column->type === 'uuid' && ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $string)) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be a UUID.');
                }
                if ($column->type === 'enum' && $column->enumValues !== [] && ! in_array($string, $column->enumValues, true)) {
                    throw new InvalidArgumentException('The ' . $label . ' field must be one of: ' . implode(', ', $column->enumValues) . '.');
                }

                return $column->type === 'integer' || $column->type === 'number' ? $this->normalizeNumeric($string, $label, $column->type) : $string;
        }
    }

    // ------------------------------------------------------------------

    private function normalizeNumeric(string $value, string $label, string $type): int|string
    {
        if ($type === 'integer') {
            if (! preg_match('/^[\-+]?\d+$/', $value)) {
                throw new InvalidArgumentException('The ' . $label . ' field must be an integer.');
            }

            return (int) $value;
        }
        if (! is_numeric($value)) {
            throw new InvalidArgumentException('The ' . $label . ' field must be a number.');
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function allowedValues(mixed $extras, Column $column): array
    {
        if (is_array($extras) && $extras !== []) {
            return array_map('strval', array_is_list($extras) ? $extras : array_keys($extras));
        }

        return $column->enumValues;
    }

    /**
     * @return list<scalar>
     */
    private function relationIds(mixed $value, bool $isForm): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (is_string($value) && $isForm) {
            $value = explode(',', $value);
        }
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Expected a list of related ids.');
        }
        foreach ($value as $id) {
            if (! is_int($id) && ! (is_string($id) && $id !== '')) {
                throw new InvalidArgumentException('Related ids must be integers or strings.');
            }
        }

        return array_values(array_unique($value, SORT_REGULAR));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assertUnique(ResolvedResource $ctx, array $data, int|string|null $id): void
    {
        $pk = $ctx->primaryKey();
        $errors = [];
        foreach ($ctx->resource->getUniqueFields() as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null || ! $ctx->table->hasColumn($field)) {
                continue;
            }
            $sql = 'SELECT 1 FROM ' . $ctx->wrappedTable() . ' WHERE ' . $ctx->grammar->wrap($field) . ' = ?';
            $bindings = [$data[$field]];
            if ($id !== null && $pk !== null) {
                $sql .= ' AND ' . $ctx->grammar->wrap($pk) . ' <> ?';
                $bindings[] = $id;
            }
            if ($ctx->db->select($sql . $ctx->grammar->limitOffset(1, null), $bindings) !== []) {
                $errors[$field] = 'The ' . $ctx->resource->label($field) . ' field must contain a unique value.';
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    private function assertFieldWritable(ResolvedResource $ctx, string $field): void
    {
        if (! in_array($field, $ctx->resource->writableFields($ctx->table, 'update'), true)) {
            throw ApiException::forbidden('The field "' . $field . '" is not writable.');
        }
    }

    /**
     * Row level security on writes: a non service caller must not create a row, or move one, outside
     * of its scope() (e.g. POST {"owner_id": "someone else"}). Runs inside the transaction, so the
     * write is rolled back.
     */
    private function assertInScope(ResolvedResource $ctx, int|string|null $id, AuthContext $auth): void
    {
        if ($id === null || $auth->isService() || $ctx->resource->getScopes() === [] || $ctx->primaryKey() === null) {
            return;
        }
        if ($this->reader->findRaw($ctx, $id, $auth) === null) {
            throw ApiException::forbidden('The record would be outside of the rows you are allowed to access.');
        }
    }

    /**
     * Primary key for a new row, or null when the database (auto increment, default) provides it.
     */
    private function newId(ResolvedResource $ctx): ?string
    {
        $pk = $ctx->primaryKey();
        $column = $pk !== null ? $ctx->table->column($pk) : null;
        if ($column === null || $column->autoIncrement || $column->generated) {
            return null;
        }
        $generator = $ctx->resource->getIdGenerator()
            ?? ($column->type === 'uuid' && $column->default === null ? 'uuid' : 'none');

        return $generator === 'none' ? null : Uuid::generate($generator);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertSql(ResolvedResource $ctx, array $data): int|string|null
    {
        $grammar = $ctx->grammar;
        $pk = $ctx->primaryKey();
        $driver = $ctx->db->driver();

        if ($data === []) {
            $sql = 'INSERT INTO ' . $ctx->wrappedTable() . ($driver === 'mysql' ? ' () VALUES ()' : ' DEFAULT VALUES');
        } else {
            $sql = 'INSERT INTO ' . $ctx->wrappedTable() . ' (' . implode(', ', array_map($grammar->wrap(...), array_keys($data)))
                . ') VALUES (' . implode(', ', array_fill(0, count($data), '?')) . ')';
        }

        if ($pk !== null && $grammar->supportsReturning()) {
            $rows = $ctx->db->select($sql . ' RETURNING ' . $grammar->wrap($pk), array_values($data));

            return $rows[0][$pk] ?? null;
        }

        $ctx->db->execute($sql, array_values($data));
        if ($pk !== null && array_key_exists($pk, $data)) {
            return $data[$pk];
        }

        return $pk !== null ? $ctx->db->lastInsertId() : null;
    }

    /**
     * Synchronize the junction table of a many_to_many relation with $ids (in order).
     *
     * @param list<scalar> $ids
     */
    private function syncManyToMany(ResolvedResource $ctx, Relation $relation, int|string|null $id, array $ids, ?AuthContext $auth = null): void
    {
        if ($id === null) {
            throw new ConfigurationException('Cannot write relation "' . $relation->name . '" without a primary key value.');
        }
        if ($auth !== null && ! $auth->isService()) {
            $visible = $this->reader->visibleRelatedIds($ctx, $relation, $ids, $auth);
            $unknown = array_values(array_diff(array_map('strval', $ids), $visible));
            if ($unknown !== []) {
                throw ValidationException::forField($relation->name, 'Unknown or inaccessible ids: ' . implode(', ', array_slice($unknown, 0, 10)) . '.');
            }
        }
        $grammar = $ctx->grammar;
        $junction = $grammar->wrapTable((string) $relation->junctionTable);
        $thisKey = $grammar->wrap((string) $relation->junctionThisKey);
        $otherKey = $grammar->wrap((string) $relation->junctionOtherKey);

        $current = $this->currentRelationIds($ctx, $relation, $id);
        $currentStrings = array_map('strval', $current);
        $newStrings = array_map('strval', $ids);

        foreach ($current as $existing) {
            if (! in_array((string) $existing, $newStrings, true)) {
                $ctx->db->execute('DELETE FROM ' . $junction . ' WHERE ' . $thisKey . ' = ? AND ' . $otherKey . ' = ?', [$id, $existing]);
            }
        }
        foreach ($ids as $position => $otherId) {
            if (! in_array((string) $otherId, $currentStrings, true)) {
                if ($relation->priorityField !== null) {
                    $ctx->db->execute(
                        'INSERT INTO ' . $junction . ' (' . $thisKey . ', ' . $otherKey . ', ' . $grammar->wrap($relation->priorityField) . ') VALUES (?, ?, ?)',
                        [$id, $otherId, $position],
                    );
                } else {
                    $ctx->db->execute('INSERT INTO ' . $junction . ' (' . $thisKey . ', ' . $otherKey . ') VALUES (?, ?)', [$id, $otherId]);
                }
            } elseif ($relation->priorityField !== null) {
                $ctx->db->execute(
                    'UPDATE ' . $junction . ' SET ' . $grammar->wrap($relation->priorityField) . ' = ? WHERE ' . $thisKey . ' = ? AND ' . $otherKey . ' = ?',
                    [$position, $id, $otherId],
                );
            }
        }
    }

    /**
     * @return list<scalar>
     */
    private function currentRelationIds(ResolvedResource $ctx, Relation $relation, int|string $id): array
    {
        $grammar = $ctx->grammar;
        $rows = $ctx->db->select(
            'SELECT ' . $grammar->wrap((string) $relation->junctionOtherKey) . ' AS id FROM ' . $grammar->wrapTable((string) $relation->junctionTable)
            . ' WHERE ' . $grammar->wrap((string) $relation->junctionThisKey) . ' = ?'
            . ($relation->priorityField !== null ? ' ORDER BY ' . $grammar->wrap($relation->priorityField) : ''),
            [$id],
        );

        return array_column($rows, 'id');
    }

    /**
     * @param list<array{0: \GroceryRestable\Resource\UploadField, 1: string}> $stored
     */
    private function storeUpload(Resource $resource, \GroceryRestable\Resource\UploadField $upload, UploadedFileInterface $file, AuthContext $auth, array &$stored): string
    {
        // Validate first so callbacks only see acceptable files.
        $this->uploads->validate($file, $upload->extensions(), $upload->maxSize, $upload->field);

        foreach ($resource->getCallbacks('before_upload') as $callback) {
            $result = $callback($file, $upload, $auth);
            if ($result === false || is_string($result)) {
                throw ValidationException::forField($upload->field, is_string($result) ? $result : 'The upload was rejected.');
            }
        }

        $custom = $resource->getCallbacks('upload')[0] ?? null;
        if ($custom !== null) {
            $value = (string) $custom($file, $upload, $auth);
            $info = ['file_name' => $value, 'path' => '', 'size' => $file->getSize(), 'mime' => $this->uploads->detectMime($file->getTempPath()), 'original_name' => $file->getClientFilename()];
        } else {
            $info = $this->uploads->storeFieldFile($upload, $file);
            $stored[] = [$upload, $info['file_name']];
            $value = $info['file_name'];
        }

        foreach ($resource->getCallbacks('after_upload') as $callback) {
            $callback($info, $upload, $auth);
        }

        return $value;
    }

    /**
     * Run "before_*" callbacks. Returning false cancels, an array replaces the data.
     *
     * @param list<mixed> $arguments First argument is the data array.
     * @return array<string, mixed>|null
     */
    private function runBefore(Resource $resource, string $event, array $arguments): ?array
    {
        $data = $arguments[0];
        $changed = false;
        foreach ($resource->getCallbacks($event) as $callback) {
            $arguments[0] = $data;
            $result = $callback(...$arguments);
            if ($result === false) {
                throw new ApiException('The operation was cancelled.', 400, 'cancelled');
            }
            if (is_array($result)) {
                $data = $result;
                $changed = true;
            }
        }

        return $changed ? $data : null;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function runAfter(Resource $resource, string $event, array $arguments): void
    {
        foreach ($resource->getCallbacks($event) as $callback) {
            $callback(...$arguments);
        }
    }

    /**
     * @param list<array{0: \GroceryRestable\Resource\UploadField, 1: string}> $stored
     */
    private function discard(array $stored): void
    {
        foreach ($stored as [$upload, $file]) {
            $this->uploads->deleteFieldFile($upload, $file);
        }
    }

    /**
     * @param list<array{0: \GroceryRestable\Resource\UploadField, 1: string}> $files
     */
    private function removeFiles(ResolvedResource $ctx, array $files): void
    {
        if ($files === [] || ! $this->store->setting('delete_replaced_files', true)) {
            return;
        }
        foreach ($files as [$upload, $file]) {
            $this->uploads->deleteFieldFile($upload, $file);
        }
    }

    private function assertEnabled(ResolvedResource $ctx, string $operation): void
    {
        if (! $ctx->resource->isEnabled($operation)) {
            throw ApiException::methodNotAllowed(sprintf('The "%s" operation is disabled for "%s".', $operation, $ctx->name()));
        }
    }
}
