<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Frontend;

use Franciscovillaquiran\LaravelRelationGenerator\Generators\CrudGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\MigrationReader;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RegisteredApiRouteInspector;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\RelationshipGenerator;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\DatabaseSchema;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaColumn;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaRelation;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaTable;
use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionClass;
use RuntimeException;

class MetadataGenerator
{
    /**
     * Build metadata for the current Laravel application without writing files.
     * Laravel must already be bootstrapped and the application's models autoloadable.
     */
    public function build(string $projectRoot): array
    {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException("Laravel project directory does not exist: {$projectRoot}");
        }
        $schema = (new MigrationReader())->analyzeCurrent(
            $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations',
            $root . DIRECTORY_SEPARATOR . '.lrg' . DIRECTORY_SEPARATOR . 'lrg-index.json',
            $root
        );
        $inventory = (new RegisteredApiRouteInspector())->inspect($root);
        $prefix = $inventory['api_prefix'] === '' ? '' : '/' . trim($inventory['api_prefix'], '/');
        if ($prefix !== '' && preg_match('~^/(?!/)[^?#\\\\]+(?<!/)$~D', $prefix) !== 1) {
            throw new RuntimeException('The registered API prefix cannot be represented in frontend metadata.');
        }
        $models = [];
        $byTable = [];
        foreach ($inventory['entities'] as $entry) {
            $class = $entry['model'];
            $expectedFile = $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Models'
                . DIRECTORY_SEPARATOR . substr($class, strrpos($class, '\\') + 1) . '.php';
            if (!is_file($expectedFile) || !class_exists($class) || !is_subclass_of($class, Model::class)) {
                throw new RuntimeException("Entity {$entry['resource']}: model {$class} is missing or is not Eloquent.");
            }
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || realpath((string) $reflection->getFileName()) !== realpath($expectedFile)) {
                throw new RuntimeException("Entity {$entry['resource']}: model class does not belong to this project.");
            }
            $model = $reflection->newInstance();
            $table = $schema->table($model->getTable());
            if ($table === null) {
                throw new RuntimeException("Entity {$entry['resource']}: table {$model->getTable()} is absent from the structural index.");
            }
            if (isset($byTable[$table->name])) {
                throw new RuntimeException("Table {$table->name} maps to multiple API entities.");
            }
            $primary = [];
            foreach ($table->indexes as $index) {
                if ($index->type === 'primary') {
                    $primary = $index->columns;
                }
            }
            if (count($primary) !== 1 || $model->getKeyName() !== $primary[0]) {
                throw new RuntimeException("Entity {$entry['resource']}: model primary key {$model->getKeyName()} does not match the singular structural primary key " . implode(', ', $primary) . '.');
            }
            $models[$table->name] = ['model' => $model, 'table' => $table, 'route' => $entry, 'primary' => $primary[0]];
            $byTable[$table->name] = $entry['resource'];
        }
        $relations = [];
        $candidates = [];
        foreach ($schema->relations as $relation) {
            if ($relation->cardinality === 'N:M' && isset($models[$relation->pivotTable])) {
                throw new RuntimeException("Pivot table {$relation->pivotTable} cannot be exported as a CRUD entity.");
            }
            if (!isset($models[$relation->sourceTable], $models[$relation->targetTable])) {
                continue;
            }
            $verified = $this->verifyRelation($relation, $models, $schema);
            if ($verified === null) {
                continue;
            }
            $relations[] = $verified;
            if ($relation->cardinality === 'N:M') {
                foreach (['source', 'target'] as $side) {
                    $other = $side === 'source' ? 'target' : 'source';
                    $tableName = $verified[$side . '_table'];
                    $candidates[$tableName][] = [
                        'relatedModelClass' => get_class($models[$verified[$other . '_table']]['model']),
                        'relation' => $verified[$side . '_name'],
                    ];
                }
            }
        }
        $crud = new CrudGenerator();
        $entities = [];
        foreach ($models as $tableName => &$item) {
            $model = $item['model'];
            $route = $item['route'];
            $item['association'] = $crud->inspectGeneratedController(
                $root,
                get_class($model),
                $item['table'],
                $candidates[$tableName] ?? []
            );
            $fields = $this->fields(
                $model,
                $item['table'],
                $item['primary'],
                $route['resource'],
                isset($route['operations']['store']) || isset($route['operations']['update'])
            );
            $item['field_names'] = array_column($fields, 'name');
            $operations = $this->operations($route['operations'], $inventory['api_prefix'], $route['resource']);
            if (isset($operations['create'])) {
                $this->assertCreateRepresentable($item['table'], $fields, $route['resource']);
            }
            $item['operations'] = $operations;
            $responseConvention = [];
            foreach (array_keys($operations) as $operation) {
                $responseConvention[$operation] = $operation === 'delete' ? 'no_content' : 'json';
            }
            $entities[] = [
                'id' => $route['resource'],
                'label' => ucfirst(str_replace(['_', '-'], ' ', $route['resource'])),
                'table' => $tableName,
                'primary_key' => $item['primary'],
                'display_field' => null,
                'fields' => $fields,
                'operations' => (object) $operations,
                'response_convention' => (object) $responseConvention,
            ];
        }
        unset($item);
        $resultRelations = $this->buildRelations($relations, $models, $byTable, $inventory);
        $verifiedSchema = (new MigrationReader())->analyzeCurrent(
            $root . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations',
            $root . DIRECTORY_SEPARATOR . '.lrg' . DIRECTORY_SEPARATOR . 'lrg-index.json',
            $root
        );
        if ($verifiedSchema->toArray() !== $schema->toArray()) {
            throw new RuntimeException('The structural schema changed during frontend metadata generation.');
        }
        usort($entities, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        usort($resultRelations, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        return [
            'contract' => 'lrg.frontend.metadata',
            'version' => '2.0',
            'api_prefix' => $prefix,
            'entities' => $entities,
            'relations' => $resultRelations,
        ];
    }

    public function json(string $projectRoot): string
    {
        return json_encode(
            $this->build($projectRoot),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . "\n";
    }

    private function fields(Model $model, SchemaTable $table, string $primary, string $entity, bool $writable): array
    {
        $fillable = $model->getFillable();
        foreach ($fillable as $field) {
            if (!is_string($field) || !isset($table->columns[$field])) {
                throw new RuntimeException("Entity {$entity}: fillable field is not a known table column.");
            }
        }
        $fields = [];
        foreach ($table->columns as $column) {
            $name = $column->name;
            if ($this->sensitive($name) || in_array($name, $model->getHidden(), true)
                || ($model->getVisible() !== [] && !in_array($name, $model->getVisible(), true))) {
                continue;
            }
            $constraints = [
                'nullable' => $column->nullable,
                'unsigned' => $column->unsigned,
                'database_required' => !$column->nullable && !$column->hasDefault && !$column->autoIncrement,
            ];
            if ($column->length !== null) {
                $constraints['max_length'] = $column->length;
            }
            if ($column->precision !== null && in_array($column->type, ['decimal', 'float', 'double'], true)) {
                $constraints['precision'] = $column->precision;
            }
            if ($column->scale !== null && in_array($column->type, ['decimal', 'float', 'double'], true)) {
                $constraints['scale'] = $column->scale;
            }
            if ($column->enumValues !== []) {
                if (count(array_unique($column->enumValues, SORT_STRING)) !== count($column->enumValues)) {
                    throw new RuntimeException("Entity {$entity}: enum field {$name} contains duplicate values.");
                }
                $constraints['enum'] = $column->enumValues;
            }
            if ($table->hasUniqueColumns([$name])) {
                $constraints['unique'] = true;
            }
            $field = [
                'name' => $name,
                'type' => $this->fieldType($column),
                'access' => $writable && $name !== $primary && in_array($name, $fillable, true) && $model->isFillable($name)
                    ? 'editable' : 'read_only',
                'constraints' => $constraints,
            ];
            if ($column->hasDefault && !$column->defaultIsExpression
                && (is_bool($column->default) || is_int($column->default) || is_float($column->default) || $column->default === null)) {
                $field['default'] = $column->default;
            }
            $fields[] = $field;
        }
        if (!in_array($primary, array_column($fields, 'name'), true)) {
            throw new RuntimeException("Entity {$entity}: its primary key is hidden or sensitive.");
        }
        usort($fields, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $fields;
    }

    private function sensitive(string $name): bool
    {
        return preg_match(
            '/password|passwd|secret|token|credential|private.?key|api.?key|access.?key|'
            . 'two.?factor|recovery.?codes|(?:^|_)(?:security|auth|salt|hash|pin|otp|mfa|key)(?:_|$)/i',
            $name
        ) === 1;
    }

    private function assertCreateRepresentable(SchemaTable $table, array $fields, string $entity): void
    {
        $editable = [];
        foreach ($fields as $field) {
            if ($field['access'] === 'editable') {
                $editable[$field['name']] = true;
            }
        }
        foreach ($table->columns as $column) {
            if (!$column->nullable && !$column->hasDefault && !$column->autoIncrement
                && !isset($editable[$column->name])
                && !in_array($column->name, ['created_at', 'updated_at'], true)) {
                throw new RuntimeException(
                    "Entity {$entity}: required column {$column->name} cannot be supplied by the published create form."
                );
            }
        }
    }

    private function fieldType(SchemaColumn $column): string
    {
        return match ($column->type) {
            'string', 'char', 'text', 'mediumText', 'longText', 'enum' => 'string',
            'integer', 'tinyInteger', 'smallInteger', 'mediumInteger', 'bigInteger', 'year' => 'integer',
            'decimal', 'float', 'double' => 'number',
            'boolean' => 'boolean',
            'date' => 'date',
            'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz' => 'datetime',
            'time', 'timeTz' => 'time',
            'json', 'jsonb' => 'json',
            'uuid' => 'uuid',
            'ulid' => 'ulid',
            'binary' => 'binary',
            default => 'unknown',
        };
    }

    private function operations(array $routes, string $prefix, string $entity): array
    {
        $names = ['index' => 'list', 'store' => 'create', 'show' => 'show', 'update' => 'update', 'destroy' => 'delete'];
        $result = [];
        foreach ($names as $action => $name) {
            if (!isset($routes[$action])) {
                continue;
            }
            $expected = match ($action) {
                'index', 'show' => ['GET'], 'store' => ['POST'],
                'update' => ['PUT', 'PATCH'], 'destroy' => ['DELETE'],
            };
            $endpoints = [];
            foreach ($expected as $method) {
                if (!in_array($method, $routes[$action]['methods'], true)) {
                    throw new RuntimeException("Entity {$entity}: registered {$action} route lacks {$method}.");
                }
                $endpoints[] = $this->endpoint($routes[$action], $prefix, $method, in_array($action, ['show', 'update', 'destroy'], true) ? 1 : 0);
            }
            $result[$name] = $name === 'update' ? $endpoints : $endpoints[0];
        }
        return $result;
    }

    private function endpoint(array $route, string $prefix, string $method, int $parameterCount): array
    {
        if (($route['domain'] ?? null) !== null) {
            throw new RuntimeException("Domain-specific route {$route['uri']} cannot be represented by this contract.");
        }
        $uri = $route['uri'];
        $prefix = trim($prefix, '/');
        if ($prefix !== '') {
            if (!str_starts_with($uri, $prefix . '/')) {
                throw new RuntimeException("Route {$uri} does not belong to API prefix {$prefix}.");
            }
            $uri = substr($uri, strlen($prefix) + 1);
        }
        if (count($route['parameters']) !== $parameterCount
            || preg_match('~^(?:[A-Za-z0-9_-]+|\{[A-Za-z_][A-Za-z0-9_]*\})(?:/(?:[A-Za-z0-9_-]+|\{[A-Za-z_][A-Za-z0-9_]*\}))*$~D', $uri) !== 1) {
            throw new RuntimeException("Route {$route['uri']} has unsupported parameters or path segments.");
        }
        return [
            'method' => $method,
            'path' => '/' . $uri,
            'parameters' => array_map(static fn (string $name): array => ['name' => $name, 'source' => 'entity_id'], $route['parameters']),
        ];
    }

    private function verifyRelation(SchemaRelation $relation, array $models, DatabaseSchema $schema): ?array
    {
        $source = $models[$relation->sourceTable]['model'];
        $target = $models[$relation->targetTable]['model'];
        if (count($relation->sourceColumns) !== 1 || count($relation->targetColumns) !== 1) {
            throw new RuntimeException("Relationship {$relation->sourceTable} to {$relation->targetTable} has unsupported composite keys.");
        }
        $definition = new RelationDefinition(
            modelA: get_class($source),
            modelB: get_class($target),
            cardinality: $relation->cardinality,
            pivotTable: $relation->pivotTable,
            modelATable: $relation->sourceTable,
            modelBTable: $relation->targetTable
        );
        $names = (new RelationshipGenerator())->inspectSchemaMethods(
            $definition,
            $relation,
            $schema,
            dirname((string) (new ReflectionClass($source))->getFileName())
        );
        if ($names === null) {
            return null;
        }
        $pivotKeys = null;
        if ($relation->cardinality === 'N:M') {
            if ($relation->sourceColumns !== [$models[$relation->sourceTable]['primary']]
                || $relation->targetColumns !== [$models[$relation->targetTable]['primary']]) {
                throw new RuntimeException("Pivot {$relation->pivotTable} must reference the published primary keys.");
            }
            $pivot = $schema->table((string) $relation->pivotTable);
            if ($pivot === null || count($pivot->foreignKeys) !== 2) {
                throw new RuntimeException("Pivot {$relation->pivotTable} is missing or has unsupported foreign keys.");
            }
            $keys = [];
            foreach ($pivot->foreignKeys as $key) {
                if (count($key->columns) !== 1 || count($key->referencedColumns) !== 1
                    || isset($keys[$key->referencedTable])) {
                    throw new RuntimeException("Pivot {$pivot->name} has ambiguous or composite foreign keys.");
                }
                $keys[$key->referencedTable] = $key;
            }
            if (!isset($keys[$relation->sourceTable], $keys[$relation->targetTable])) {
                throw new RuntimeException("Pivot {$pivot->name} does not match the associated entities.");
            }
            $sourceKey = $keys[$relation->sourceTable];
            $targetKey = $keys[$relation->targetTable];
            $pivotKeys = [
                'source' => ['columns' => $sourceKey->columns, 'referenced_columns' => $sourceKey->referencedColumns],
                'target' => ['columns' => $targetKey->columns, 'referenced_columns' => $targetKey->referencedColumns],
            ];
            foreach ($pivot->columns as $column) {
                if (!in_array($column->name, [$sourceKey->columns[0], $targetKey->columns[0], 'created_at', 'updated_at'], true)
                    && !$column->autoIncrement) {
                    throw new RuntimeException("Pivot {$pivot->name} has unsupported business column {$column->name}.");
                }
            }
        }
        return [
            'schema' => $relation, 'source_table' => $relation->sourceTable, 'target_table' => $relation->targetTable,
            'source_name' => $names['relationA'], 'target_name' => $names['relationB'],
            'pivot_keys' => $pivotKeys,
        ];
    }

    private function buildRelations(array $relations, array $models, array $byTable, array $inventory): array
    {
        $result = [];
        $usedRoutes = [];
        foreach ($relations as $item) {
            $relation = $item['schema'];
            $sourceTable = $item['source_table'];
            $targetTable = $item['target_table'];
            $sourceFields = $relation->sourceColumns;
            $targetFields = $relation->targetColumns;
            $pivotKeys = $item['pivot_keys'];
            if (array_diff($sourceFields, $models[$sourceTable]['field_names']) !== []
                || array_diff($targetFields, $models[$targetTable]['field_names']) !== []) {
                throw new RuntimeException("Relationship {$sourceTable} to {$targetTable} references hidden or sensitive fields.");
            }
            $association = [];
            if ($relation->cardinality === 'N:M') {
                $owner = null;
                foreach (['source', 'target'] as $side) {
                    $other = $side === 'source' ? 'target' : 'source';
                    $ownerTable = $item[$side . '_table'];
                    $relatedTable = $item[$other . '_table'];
                    $expected = ['relatedModelClass' => get_class($models[$relatedTable]['model']), 'relation' => $item[$side . '_name']];
                    if ($models[$ownerTable]['association'] !== [$expected]) {
                        continue;
                    }
                    if ($owner !== null) {
                        throw new RuntimeException("Pivot {$relation->pivotTable} has multiple association owners.");
                    }
                    $owner = $ownerTable;
                    if ($side === 'target') {
                        [$sourceTable, $targetTable] = [$targetTable, $sourceTable];
                        [$sourceFields, $targetFields] = [$targetFields, $sourceFields];
                        $pivotKeys = ['source' => $pivotKeys['target'], 'target' => $pivotKeys['source']];
                    }
                    foreach (['create', 'update'] as $operation) {
                        if (isset($models[$ownerTable]['operations'][$operation])) {
                            $association[$operation] = [
                                'endpoints' => $operation === 'update'
                                    ? $models[$ownerTable]['operations'][$operation]
                                    : [$models[$ownerTable]['operations'][$operation]],
                                'body_field' => 'related_ids',
                                'semantics' => 'replace',
                            ];
                        }
                    }
                    $action = 'App\\Http\\Controllers\\Api\\' . class_basename($models[$ownerTable]['model'])
                        . 'Controller@sync' . Str::studly(class_basename($models[$relatedTable]['model']));
                    foreach ($inventory['associations'] as $index => $route) {
                        if ($route['action'] !== $action) {
                            continue;
                        }
                        if (!in_array('PUT', $route['methods'], true)) {
                            throw new RuntimeException("Association route {$route['uri']} does not accept PUT.");
                        }
                        $association['replace'] = $this->endpoint($route, $inventory['api_prefix'], 'PUT', 1)
                            + ['body_field' => 'related_ids'];
                        $usedRoutes[$index] = true;
                    }
                }
            }
            $id = $relation->cardinality === 'N:M'
                ? 'pivot:' . $relation->pivotTable
                : 'foreign:' . $relation->foreignKeyTable . '.' . $relation->foreignKey;
            $result[] = [
                'id' => $id,
                'name' => $sourceTable === $item['source_table'] ? $item['source_name'] : $item['target_name'],
                'inverse_name' => $sourceTable === $item['source_table'] ? $item['target_name'] : $item['source_name'],
                'cardinality' => $relation->cardinality,
                'source' => $byTable[$sourceTable],
                'target' => $byTable[$targetTable],
                'source_fields' => $sourceFields,
                'target_fields' => $targetFields,
                'foreign_key' => $relation->cardinality === 'N:M' ? null : [
                    'table' => $relation->foreignKeyTable,
                    'columns' => $relation->foreignKeyColumns,
                    'referenced_table' => $relation->referencedTable,
                    'referenced_columns' => $relation->referencedColumns,
                ],
                'pivot_table' => $relation->pivotTable,
                'pivot_foreign_keys' => $pivotKeys,
                'association_operations' => (object) $association,
            ];
        }
        if (count($usedRoutes) !== count($inventory['associations'])) {
            throw new RuntimeException('A registered association route could not be matched to a verified LRG relationship and controller.');
        }
        return $result;
    }
}
