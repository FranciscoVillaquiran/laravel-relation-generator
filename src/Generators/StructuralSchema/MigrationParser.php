<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

use ParseError;

class MigrationParser
{
    private array $tokens = [];

    private string $file = '';

    private array $schemaAliases = [
        'Schema',
        'Illuminate\\Support\\Facades\\Schema',
    ];

    private array $databaseAliases = [
        'DB',
        'Illuminate\\Support\\Facades\\DB',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function parse(string $file): array
    {
        $this->file = $file;
        $this->schemaAliases = [
            'Schema',
            'Illuminate\\Support\\Facades\\Schema',
        ];
        $this->databaseAliases = [
            'DB',
            'Illuminate\\Support\\Facades\\DB',
        ];

        $source = file_get_contents($file);
        if ($source === false) {
            $this->fail(1, 'migration file', 'the file contents');
        }

        try {
            $rawTokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $error) {
            $this->fail(1, 'PHP syntax', 'a syntactically valid migration (' . $error->getMessage() . ')');
        }

        $this->tokens = [];
        $currentLine = 1;
        foreach ($rawTokens as $token) {
            if (is_array($token)) {
                $currentLine = $token[2];
                if (in_array($token[0], [
                    T_WHITESPACE,
                    T_COMMENT,
                    T_DOC_COMMENT,
                    T_OPEN_TAG,
                    T_CLOSE_TAG,
                ], true)) {
                    continue;
                }

                $this->tokens[] = [
                    'type' => $token[0],
                    'value' => $token[1],
                    'line' => $token[2],
                ];
            } else {
                $this->tokens[] = [
                    'type' => null,
                    'value' => $token,
                    'line' => $currentLine,
                ];
            }
        }

        $this->resolveFacadeAliases();
        $migrationHasSchemaCalls = $this->hasSchemaCalls();
        if ($migrationHasSchemaCalls) {
            $this->tokens = $this->upMethodTokens();
        }

        $hasSchemaCalls = $this->hasSchemaCalls();
        if ($migrationHasSchemaCalls) {
            if ($hasSchemaCalls) {
                $this->rejectDynamicControlFlow();
            }
            $this->validateUpStatements();
        }
        $this->rejectRawSchemaSql();

        $operations = [];
        $count = count($this->tokens);
        for ($index = 0; $index < $count; $index++) {
            if (!$this->isSchemaCallAt($index)) {
                continue;
            }

            $method = $this->tokens[$index + 2]['value'];
            if (!in_array($method, ['create', 'table', 'drop', 'dropIfExists', 'rename'], true)) {
                if (!in_array($method, [
                    'enableForeignKeyConstraints',
                    'disableForeignKeyConstraints',
                ], true)) {
                    $this->fail(
                        $this->tokens[$index]['line'],
                        "Schema::{$method}",
                        'the schema effect of this Schema method'
                    );
                }
                $arguments = $this->readArguments($index + 3);
                $this->assertArgumentCount($arguments, 0, 0, $this->tokens[$index]['line'], "Schema::{$method}");
                continue;
            }

            $line = $this->tokens[$index]['line'];
            $arguments = $this->readArguments($index + 3);
            $expectedCount = $method === 'rename' ? 2 : (in_array($method, ['drop', 'dropIfExists'], true) ? 1 : 2);
            $this->assertArgumentCount(
                $arguments,
                $expectedCount,
                $expectedCount,
                $line,
                "Schema::{$method}"
            );
            $tableName = $this->staticString($arguments[0] ?? [], $line, "Schema::{$method}");

            if ($method === 'drop' || $method === 'dropIfExists') {
                $operations[] = [
                    'operation' => 'drop_table',
                    'table' => $tableName,
                    'if_exists' => $method === 'dropIfExists',
                    'file' => $file,
                    'line' => $line,
                    'statements' => [],
                ];
                continue;
            }

            if ($method === 'rename') {
                $newTable = $this->staticString($arguments[1] ?? [], $line, 'Schema::rename');
                $operations[] = [
                    'operation' => 'rename_table',
                    'table' => $tableName,
                    'new_table' => $newTable,
                    'file' => $file,
                    'line' => $line,
                    'statements' => [],
                ];
                continue;
            }

            if (!isset($arguments[1])) {
                $this->fail($line, "Schema::{$method}", 'the Blueprint closure');
            }

            $operations[] = [
                'operation' => $method === 'create' ? 'create_table' : 'alter_table',
                'table' => $tableName,
                'file' => $file,
                'line' => $line,
                'statements' => $this->parseBlueprintClosure($arguments[1], $line, "Schema::{$method}('{$tableName}')"),
            ];
        }

        return $operations;
    }

    private function parseBlueprintClosure(array $tokens, int $line, string $structure): array
    {
        $functionIndex = null;
        foreach ($tokens as $index => $token) {
            if ($token['type'] === T_FN) {
                $this->fail($line, $structure, 'the body of an arrow-function Blueprint callback');
            }
            if ($token['type'] === T_FUNCTION) {
                $functionIndex = $index;
                break;
            }
        }

        if ($functionIndex === null) {
            $this->fail($line, $structure, 'a static function Blueprint callback');
        }

        $bodyStart = null;
        for ($index = $functionIndex + 1, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]['value'] === '{') {
                $bodyStart = $index;
                break;
            }
        }
        if ($bodyStart === null) {
            $this->fail($line, $structure, 'a braced Blueprint callback body');
        }

        $bodyEnd = $this->matchingToken($tokens, $bodyStart, '{', '}');
        if ($bodyEnd === null) {
            $this->fail($line, $structure, 'a complete Blueprint callback body');
        }

        $statements = [];
        foreach ($this->splitStatements(array_slice($tokens, $bodyStart + 1, $bodyEnd - $bodyStart - 1)) as $statementTokens) {
            if ($statementTokens === []) {
                continue;
            }
            $statement = $this->parseBlueprintStatement($statementTokens, $structure);
            if ($statement['operation'] === 'multiple') {
                array_push($statements, ...$statement['statements']);
            } elseif ($statement['operation'] !== 'table_option') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    private function parseBlueprintStatement(array $tokens, string $structure): array
    {
        $line = $tokens[0]['line'];
        if (
            ($tokens[0]['type'] ?? null) !== T_VARIABLE
            || ($tokens[1]['value'] ?? null) !== '->'
        ) {
            $this->fail($line, $structure, 'a literal Blueprint method chain (conditional or dynamic statements are not supported)');
        }

        $calls = [];
        $index = 2;
        while ($index < count($tokens)) {
            if (!$this->isIdentifier($tokens[$index]) || ($tokens[$index + 1]['value'] ?? null) !== '(') {
                $this->fail($line, $structure, 'the method name and literal arguments in the Blueprint chain');
            }

            $method = $tokens[$index]['value'];
            $argumentTokens = $this->readArgumentsFromTokens($tokens, $index + 1);
            $calls[] = [
                'method' => $method,
                'arguments' => array_map(
                    fn (array $argument): mixed => $this->staticValue($argument, $line, $structure . ' -> ' . $method),
                    $argumentTokens
                ),
                'line' => $tokens[$index]['line'],
            ];

            $closing = $this->matchingToken($tokens, $index + 1, '(', ')');
            $index = $closing + 1;
            if ($index >= count($tokens)) {
                break;
            }
            if (($tokens[$index]['value'] ?? null) !== '->') {
                if (($tokens[$index]['value'] ?? null) === ';' && $index === count($tokens) - 1) {
                    break;
                }
                $this->fail($line, $structure, 'a continuous Blueprint method chain');
            }
            $index++;
        }

        if ($calls === []) {
            $this->fail($line, $structure, 'at least one Blueprint method');
        }

        return $this->interpretCalls($calls, $structure);
    }

    private function interpretCalls(array $calls, string $structure): array
    {
        $first = $calls[0];
        $method = $first['method'];
        $line = $first['line'];
        $description = $structure . ' -> ' . implode(' -> ', array_column($calls, 'method'));

        if (in_array($method, ['timestamps', 'timestampsTz', 'nullableTimestamps'], true)) {
            $this->assertArgumentCount($first['arguments'], 0, 1, $line, $description);
            if (count($calls) !== 1) {
                $this->fail($line, $description, 'the schema effect of chained timestamp modifiers');
            }
            $precision = $first['arguments'][0] ?? null;
            if ($precision !== null && (!is_int($precision) || $precision < 0)) {
                $this->fail($line, $description, 'a non-negative integer timestamp precision');
            }

            $statements = [];
            foreach (['created_at', 'updated_at'] as $name) {
                $columnType = $method === 'timestampsTz' ? 'timestampTz' : 'timestamp';
                $column = $this->columnData(
                    $name,
                    $columnType,
                    $precision === null ? [] : [$precision],
                    $precision === null ? [] : ['precision' => 0],
                    true
                );
                $statements[] = [
                    'operation' => 'column',
                    'column' => $column,
                    'indexes' => [],
                    'foreign_keys' => [],
                    'line' => $line,
                    'description' => $description,
                ];
            }

            return ['operation' => 'multiple', 'statements' => $statements, 'line' => $line, 'description' => $description];
        }

        if (in_array($method, ['softDeletes', 'softDeletesTz'], true)) {
            $this->assertArgumentCount($first['arguments'], 0, 2, $line, $description);
            if (count($calls) !== 1) {
                $this->fail($line, $description, 'the schema effect of chained soft-delete modifiers');
            }
            $name = $first['arguments'][0] ?? 'deleted_at';
            if (!is_string($name)) {
                $this->fail($line, $description, 'the soft-delete column name');
            }
            $precision = $first['arguments'][1] ?? null;
            if ($precision !== null && (!is_int($precision) || $precision < 0)) {
                $this->fail($line, $description, 'a non-negative integer soft-delete timestamp precision');
            }
            return [
                'operation' => 'column',
                'column' => $this->columnData(
                    $name,
                    $method === 'softDeletesTz' ? 'timestampTz' : 'timestamp',
                    $precision === null ? [] : [$precision],
                    $precision === null ? [] : ['precision' => 0],
                    true
                ),
                'indexes' => [],
                'foreign_keys' => [],
                'line' => $line,
                'description' => $description,
            ];
        }

        if ($method === 'rememberToken') {
            $this->assertArgumentCount($first['arguments'], 0, 0, $line, $description);

            return $this->columnStatement([
                [
                    'method' => 'string',
                    'arguments' => ['remember_token', 100],
                    'line' => $line,
                ],
                [
                    'method' => 'nullable',
                    'arguments' => [],
                    'line' => $line,
                ],
                ...array_slice($calls, 1),
            ], $structure);
        }

        if (in_array($method, [
            'morphs',
            'nullableMorphs',
            'numericMorphs',
            'nullableNumericMorphs',
            'uuidMorphs',
            'nullableUuidMorphs',
            'ulidMorphs',
            'nullableUlidMorphs',
        ], true)) {
            if (count($calls) !== 1) {
                $this->fail($line, $description, 'the schema effect of chained polymorphic column helpers');
            }

            return $this->morphsStatement($first, $structure);
        }

        if (isset(self::COLUMN_TYPES[$method])) {
            return $this->columnStatement($calls, $structure);
        }

        if (in_array($method, ['foreign', 'index', 'unique', 'primary', 'fullText', 'spatialIndex'], true)) {
            return $this->constraintStatement($calls, $structure);
        }

        if (in_array($method, ['dropColumn', 'dropColumns'], true)) {
            $this->assertSingleBlueprintCall($calls, $line, $description);
            return [
                'operation' => 'drop_column',
                'columns' => $this->stringList($first['arguments'], $line, $description),
                'line' => $line,
                'description' => $description,
            ];
        }

        if ($method === 'renameColumn') {
            $this->assertSingleBlueprintCall($calls, $line, $description);
            $columns = $this->stringList($first['arguments'], $line, $description);
            if (count($columns) !== 2) {
                $this->fail($line, $description, 'the old and new names for the renamed column');
            }
            return [
                'operation' => 'rename_column',
                'columns' => $columns,
                'line' => $line,
                'description' => $description,
            ];
        }

        if (in_array($method, ['dropIndex', 'dropUnique', 'dropPrimary', 'dropFullText', 'dropSpatialIndex'], true)) {
            $this->assertSingleBlueprintCall($calls, $line, $description);
            $this->assertArgumentCount($first['arguments'], 1, 1, $line, $description);
            $type = match ($method) {
                'dropUnique' => 'unique',
                'dropPrimary' => 'primary',
                'dropFullText' => 'fullText',
                'dropSpatialIndex' => 'spatialIndex',
                default => 'index',
            };
            $arguments = $first['arguments'];
            $name = isset($arguments[0]) && is_string($arguments[0]) ? $arguments[0] : null;
            $columns = $name === null ? $this->stringList($arguments, $line, $description) : [];

            return [
                'operation' => 'drop_index',
                'type' => $type,
                'name' => $name,
                'columns' => $columns,
                'line' => $line,
                'description' => $description,
            ];
        }

        if ($method === 'dropForeign') {
            $this->assertSingleBlueprintCall($calls, $line, $description);
            $arguments = $first['arguments'];
            $this->assertArgumentCount($arguments, 1, 1, $line, $description);
            $name = isset($arguments[0]) && is_string($arguments[0]) ? $arguments[0] : null;
            return [
                'operation' => 'drop_foreign_key',
                'name' => $name,
                'columns' => $name === null ? $this->stringList($arguments, $line, $description) : [],
                'line' => $line,
                'description' => $description,
            ];
        }

        if (in_array($method, ['charset', 'collation', 'engine', 'comment'], true)) {
            $this->assertSingleBlueprintCall($calls, $line, $description);
            return ['operation' => 'table_option', 'line' => $line, 'description' => $description];
        }

        $this->fail($line, $description, 'the schema effect of this Blueprint method');
    }

    private function morphsStatement(array $call, string $structure): array
    {
        $method = $call['method'];
        $line = $call['line'];
        $description = $structure . ' -> ' . $method;
        $arguments = $call['arguments'];
        $this->assertArgumentCount($arguments, 1, 3, $line, $description);

        $name = $arguments[0] ?? null;
        if (!is_string($name) || $name === '') {
            $this->fail($line, $description, 'the polymorphic relationship name');
        }
        $indexName = $arguments[1] ?? null;
        if ($indexName !== null && (!is_string($indexName) || $indexName === '')) {
            $this->fail($line, $description, 'a literal polymorphic index name');
        }
        $after = $arguments[2] ?? null;
        if ($after !== null && (!is_string($after) || $after === '')) {
            $this->fail($line, $description, 'a literal column name for the after position');
        }

        $nullable = str_starts_with($method, 'nullable');
        $idMethod = match ($method) {
            'uuidMorphs', 'nullableUuidMorphs' => 'uuid',
            'ulidMorphs', 'nullableUlidMorphs' => 'ulid',
            default => 'unsignedBigInteger',
        };
        $typeColumn = "{$name}_type";
        $idColumn = "{$name}_id";
        $statements = [];

        foreach ([
            ['method' => 'string', 'name' => $typeColumn],
            ['method' => $idMethod, 'name' => $idColumn],
        ] as $columnDefinition) {
            $columnCalls = [[
                'method' => $columnDefinition['method'],
                'arguments' => [$columnDefinition['name']],
                'line' => $line,
            ]];
            if ($nullable) {
                $columnCalls[] = [
                    'method' => 'nullable',
                    'arguments' => [],
                    'line' => $line,
                ];
            }

            $statement = $this->columnStatement($columnCalls, $structure);
            $statement['description'] = $description;
            $statements[] = $statement;
        }

        $statements[] = [
            'operation' => 'index',
            'index' => $this->indexData('index', [$typeColumn, $idColumn], $indexName, $line, $description),
            'line' => $line,
            'description' => $description,
        ];

        return [
            'operation' => 'multiple',
            'statements' => $statements,
            'line' => $line,
            'description' => $description,
        ];
    }

    private function columnStatement(array $calls, string $structure): array
    {
        $first = $calls[0];
        $line = $first['line'];
        $description = $structure . ' -> ' . implode(' -> ', array_column($calls, 'method'));
        $type = self::COLUMN_TYPES[$first['method']];
        $arguments = $first['arguments'];
        $maxArguments = ($type['enum'] ?? false)
            ? 2
            : 1 + count($type['parameters']);
        $minimumArguments = $first['method'] === 'id' ? 0 : 1;
        $this->assertArgumentCount($arguments, $minimumArguments, $maxArguments, $line, $description);
        $name = $arguments[0] ?? null;

        if (!is_string($name) || $name === '') {
            if ($first['method'] === 'id') {
                $name = 'id';
            } else {
                $this->fail($line, $description, 'the column name');
            }
        }

        $column = $this->columnData(
            $name,
            $type['type'],
            $arguments,
            $type['parameters'],
            $type['nullable'] ?? false
        );
        foreach ($type['parameters'] as $parameter => $position) {
            $value = $arguments[$position] ?? null;
            if ($value !== null && !is_int($value)) {
                $this->fail($line, $description, "an integer {$parameter} value");
            }
            if ($value !== null && $value < 0) {
                $this->fail($line, $description, "a non-negative {$parameter} value");
            }
        }
        $column['unsigned'] = $column['unsigned'] || ($type['unsigned'] ?? false);
        $column['auto_increment'] = $column['auto_increment'] || ($type['auto_increment'] ?? false);
        $specified = ['name', 'type'];
        foreach ($type['parameters'] as $parameter => $position) {
            $specified[] = $parameter;
        }
        if (($type['nullable'] ?? false) === true) {
            $specified[] = 'nullable';
        }
        if ($type['unsigned'] ?? false) {
            $specified[] = 'unsigned';
        }
        if ($type['auto_increment'] ?? false) {
            $specified[] = 'auto_increment';
        }
        if ($type['enum'] ?? false) {
            $column['enum_values'] = $this->stringList([$arguments[1] ?? null], $line, $description);
            $specified[] = 'enum_values';
        }

        $indexes = [];
        $foreignKeys = [];
        $foreign = null;
        foreach (array_slice($calls, 1) as $call) {
            $modifier = $call['method'];
            $values = $call['arguments'];

            if ($modifier === 'nullable') {
                $this->assertArgumentCount($values, 0, 1, $call['line'], $description);
                $column['nullable'] = $values[0] ?? true;
                if (!is_bool($column['nullable'])) {
                    $this->fail($call['line'], $description, 'a boolean nullable modifier');
                }
                $specified[] = 'nullable';
            } elseif ($modifier === 'default') {
                $this->assertArgumentCount($values, 1, 1, $call['line'], $description);
                $column['default'] = $values[0] ?? null;
                $column['has_default'] = true;
                $column['default_is_expression'] = false;
                $specified[] = 'default';
                $specified[] = 'has_default';
                $specified[] = 'default_is_expression';
            } elseif ($modifier === 'useCurrent') {
                $this->assertArgumentCount($values, 0, 0, $call['line'], $description);
                $column['default'] = 'CURRENT_TIMESTAMP';
                $column['has_default'] = true;
                $column['default_is_expression'] = true;
                $specified[] = 'default';
                $specified[] = 'has_default';
                $specified[] = 'default_is_expression';
            } elseif ($modifier === 'unsigned') {
                $this->assertArgumentCount($values, 0, 0, $call['line'], $description);
                $column['unsigned'] = true;
                $specified[] = 'unsigned';
            } elseif ($modifier === 'autoIncrement') {
                $this->assertArgumentCount($values, 0, 0, $call['line'], $description);
                $column['auto_increment'] = true;
                $specified[] = 'auto_increment';
            } elseif (in_array($modifier, ['primary', 'unique', 'index', 'fullText', 'spatialIndex'], true)) {
                $this->assertArgumentCount($values, 0, 1, $call['line'], $description);
                $indexes[] = $this->indexData(
                    $modifier === 'primary' ? 'primary' : ($modifier === 'unique' ? 'unique' : $modifier),
                    [$name],
                    $values[0] ?? null,
                    $call['line'],
                    $description
                );
            } elseif ($modifier === 'constrained') {
                $this->assertArgumentCount($values, 0, 3, $call['line'], $description);
                $foreign = $this->constrainedForeignKey($name, $values, $call['line'], $description);
            } elseif ($modifier === 'references') {
                $this->assertArgumentCount($values, 1, 1, $call['line'], $description);
                $foreign ??= $this->emptyForeignKey([$name]);
                $foreign['referenced_columns'] = $this->stringList($values, $call['line'], $description);
            } elseif ($modifier === 'on') {
                $this->assertArgumentCount($values, 1, 1, $call['line'], $description);
                $foreign ??= $this->emptyForeignKey([$name]);
                $foreign['referenced_table'] = $this->stringArgument($values, 0, $call['line'], $description);
            } elseif (in_array($modifier, ['onDelete', 'onUpdate'], true)) {
                $this->assertArgumentCount($values, 1, 1, $call['line'], $description);
                $foreign ??= $this->emptyForeignKey([$name]);
                $foreign[$modifier === 'onDelete' ? 'on_delete' : 'on_update'] =
                    $this->referentialAction($values, $call['line'], $description);
            } elseif (in_array($modifier, ['cascadeOnDelete', 'restrictOnDelete', 'nullOnDelete', 'noActionOnDelete', 'cascadeOnUpdate', 'restrictOnUpdate', 'nullOnUpdate', 'noActionOnUpdate'], true)) {
                $this->assertArgumentCount($values, 0, 0, $call['line'], $description);
                $foreign ??= $this->emptyForeignKey([$name]);
                $action = match (true) {
                    str_starts_with($modifier, 'cascade') => 'cascade',
                    str_starts_with($modifier, 'restrict') => 'restrict',
                    str_starts_with($modifier, 'null') => 'set null',
                    default => 'no action',
                };
                $foreign[str_ends_with($modifier, 'Delete') ? 'on_delete' : 'on_update'] = $action;
            } elseif (in_array($modifier, ['after', 'first', 'comment', 'change'], true)) {
                $this->assertArgumentCount(
                    $values,
                    $modifier === 'first' || $modifier === 'change' ? 0 : 1,
                    $modifier === 'first' || $modifier === 'change' ? 0 : 1,
                    $call['line'],
                    $description
                );
                if ($modifier === 'change') {
                    $column['change'] = true;
                    $specified[] = 'change';
                }
            } else {
                $this->fail($call['line'], $description, "the effect of the column modifier {$modifier}");
            }
        }

        $column['specified'] = array_values(array_unique($specified));
        if ($foreign !== null) {
            if ($foreign['referenced_table'] === null && $foreign['referenced_columns'] !== []) {
                $this->fail($line, $description, 'the table referenced by the foreign key');
            }
            $foreignKeys[] = $this->finishForeignKey($foreign, $line, $description);
        }

        return [
            'operation' => 'column',
            'column' => $column,
            'indexes' => $indexes,
            'foreign_keys' => $foreignKeys,
            'line' => $line,
            'description' => $description,
        ];
    }

    private function constraintStatement(array $calls, string $structure): array
    {
        $first = $calls[0];
        $line = $first['line'];
        $description = $structure . ' -> ' . implode(' -> ', array_column($calls, 'method'));
        $method = $first['method'];
        $arguments = $first['arguments'];
        $columns = $this->stringList([$arguments[0] ?? null], $line, $description);

        if ($method !== 'foreign') {
            $this->assertSingleBlueprintCall($calls, $line, $description);
            $this->assertArgumentCount($arguments, 1, 2, $line, $description);
            $type = match ($method) {
                'primary' => 'primary',
                'unique' => 'unique',
                'fullText' => 'fullText',
                'spatialIndex' => 'spatialIndex',
                default => 'index',
            };

            return [
                'operation' => 'index',
                'index' => $this->indexData($type, $columns, $arguments[1] ?? null, $line, $description),
                'line' => $line,
                'description' => $description,
            ];
        }

        $this->assertArgumentCount($arguments, 1, 2, $line, $description);
        $foreign = $this->emptyForeignKey($columns);
        if (isset($arguments[1]) && !is_string($arguments[1])) {
            $this->fail($line, $description, 'a literal foreign-key constraint name');
        }
        $foreign['name'] = $arguments[1] ?? null;

        foreach (array_slice($calls, 1) as $call) {
            $values = $call['arguments'];
            switch ($call['method']) {
                case 'references':
                    $foreign['referenced_columns'] = $this->stringList($values, $call['line'], $description);
                    break;
                case 'on':
                    $foreign['referenced_table'] = $this->stringArgument($values, 0, $call['line'], $description);
                    break;
                case 'onDelete':
                    $foreign['on_delete'] = $this->referentialAction($values, $call['line'], $description);
                    break;
                case 'onUpdate':
                    $foreign['on_update'] = $this->referentialAction($values, $call['line'], $description);
                    break;
                case 'cascadeOnDelete':
                case 'restrictOnDelete':
                case 'nullOnDelete':
                case 'noActionOnDelete':
                case 'cascadeOnUpdate':
                case 'restrictOnUpdate':
                case 'nullOnUpdate':
                case 'noActionOnUpdate':
                    $action = match (true) {
                        str_starts_with($call['method'], 'cascade') => 'cascade',
                        str_starts_with($call['method'], 'restrict') => 'restrict',
                        str_starts_with($call['method'], 'null') => 'set null',
                        default => 'no action',
                    };
                    $foreign[str_ends_with($call['method'], 'Delete') ? 'on_delete' : 'on_update'] = $action;
                    break;
                default:
                    $this->fail($call['line'], $description, "the effect of the foreign-key modifier {$call['method']}");
            }
        }

        return [
            'operation' => 'foreign_key',
            'foreign_key' => $this->finishForeignKey($foreign, $line, $description),
            'line' => $line,
            'description' => $description,
        ];
    }

    private function columnData(
        string $name,
        string $type,
        array $arguments,
        array $parameters,
        bool $nullable
    ): array {
        $data = [
            'name' => $name,
            'type' => $type,
            'length' => null,
            'precision' => null,
            'scale' => null,
            'nullable' => $nullable,
            'default' => null,
            'has_default' => false,
            'default_is_expression' => false,
            'auto_increment' => false,
            'unsigned' => false,
            'enum_values' => [],
            'change' => false,
            'specified' => ['name', 'type'],
        ];

        foreach ($parameters as $parameter => $position) {
            if (!array_key_exists($position, $arguments) || $arguments[$position] === null) {
                continue;
            }
            $data[$parameter] = $arguments[$position];
            $data['specified'][] = $parameter;
        }

        if ($type === 'string') {
            $data['length'] ??= 255;
        } elseif ($type === 'char') {
            $data['length'] ??= 1;
        } elseif ($type === 'decimal') {
            $data['precision'] ??= 8;
            $data['scale'] ??= 2;
        } elseif (in_array($type, ['float', 'double'], true)) {
            $data['precision'] ??= 8;
            $data['scale'] ??= 2;
        }

        return $data;
    }

    private function indexData(
        string $type,
        array $columns,
        mixed $name,
        int $line,
        string $description
    ): array {
        if (!is_string($name) && $name !== null) {
            $this->fail($line, $description, 'a literal constraint name');
        }

        return [
            'type' => $type,
            'columns' => $columns,
            'name' => $name,
            'unique' => in_array($type, ['primary', 'unique'], true),
        ];
    }

    private function constrainedForeignKey(string $column, array $arguments, int $line, string $description): array
    {
        $table = $arguments[0] ?? null;
        $referencedColumn = $arguments[1] ?? 'id';
        $name = $arguments[2] ?? null;

        if ($table === null) {
            if (!str_ends_with($column, '_id')) {
                $this->fail($line, $description, 'the table name for constrained() without an explicit table');
            }

            $singular = substr($column, 0, -3);
            if (class_exists(\Illuminate\Support\Pluralizer::class)) {
                $table = \Illuminate\Support\Pluralizer::plural($singular);
            } else {
                $this->fail($line, $description, 'the Laravel pluralizer required to resolve the implicit constrained() table');
            }
        }

        if (!is_string($table) || !is_string($referencedColumn) || (!is_string($name) && $name !== null)) {
            $this->fail($line, $description, 'literal table, referenced-column, and constraint names for constrained()');
        }

        return [
            'name' => $name,
            'columns' => [$column],
            'referenced_table' => $table,
            'referenced_columns' => [$referencedColumn],
            'on_delete' => null,
            'on_update' => null,
        ];
    }

    private function emptyForeignKey(array $columns): array
    {
        return [
            'name' => null,
            'columns' => $columns,
            'referenced_table' => null,
            'referenced_columns' => [],
            'on_delete' => null,
            'on_update' => null,
        ];
    }

    private function finishForeignKey(array $foreignKey, int $line, string $description): array
    {
        if ($foreignKey['referenced_table'] === null || $foreignKey['referenced_columns'] === []) {
            $this->fail($line, $description, 'the referenced table and columns for the foreign key');
        }

        return $foreignKey;
    }

    private function referentialAction(array $arguments, int $line, string $description): string
    {
        $action = $arguments[0] ?? null;
        if (!is_string($action)) {
            $this->fail($line, $description, 'a literal ON DELETE/ON UPDATE action');
        }

        $action = strtolower(str_replace('_', ' ', trim($action)));
        if (!in_array($action, ['cascade', 'restrict', 'set null', 'set default', 'no action'], true)) {
            $this->fail($line, $description, "a supported referential action instead of {$action}");
        }

        return $action;
    }

    private function stringList(array $arguments, int $line, string $description): array
    {
        if (count($arguments) === 1 && is_array($arguments[0])) {
            $arguments = $arguments[0];
        }

        if ($arguments === [] || array_filter($arguments, static fn ($value): bool => !is_string($value)) !== []) {
            $this->fail($line, $description, 'one or more literal column names');
        }

        return array_values($arguments);
    }

    private function stringArgument(array $arguments, int $index, int $line, string $description): string
    {
        if (!isset($arguments[$index]) || !is_string($arguments[$index]) || $arguments[$index] === '') {
            $this->fail($line, $description, 'a literal table or column name');
        }

        return $arguments[$index];
    }

    private function staticString(array $tokens, int $line, string $structure): string
    {
        $value = $this->staticValue($tokens, $line, $structure);
        if (!is_string($value) || $value === '') {
            $this->fail($line, $structure, 'a non-empty literal table name');
        }

        return $value;
    }

    private function staticValue(array $tokens, int $line, string $structure): mixed
    {
        $tokens = array_values(array_filter(
            $tokens,
            static fn (array $token): bool => !in_array($token['type'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        if ($tokens === []) {
            $this->fail($line, $structure, 'a literal argument');
        }

        if (count($tokens) === 1) {
            $token = $tokens[0];
            if ($token['type'] === T_CONSTANT_ENCAPSED_STRING) {
                $quote = $token['value'][0];
                $body = substr($token['value'], 1, -1);
                return $quote === "'"
                    ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body)
                    : stripcslashes($body);
            }
            if ($token['type'] === T_LNUMBER) {
                return intval($token['value'], 0);
            }
            if ($token['type'] === T_DNUMBER) {
                return (float) $token['value'];
            }
            if ($token['type'] === T_STRING) {
                return match (strtolower($token['value'])) {
                    'true' => true,
                    'false' => false,
                    'null' => null,
                    default => $this->unsupportedLiteral($line, $structure),
                };
            }
        }

        if ($tokens[0]['value'] === '-' && count($tokens) === 2) {
            $number = $this->staticValue([$tokens[1]], $line, $structure);
            if (is_int($number) || is_float($number)) {
                return -$number;
            }
        }

        if ($tokens[0]['value'] === '[' && $tokens[count($tokens) - 1]['value'] === ']') {
            $inner = array_slice($tokens, 1, -1);
            if ($inner === []) {
                return [];
            }
            return array_map(
                fn (array $part): mixed => $this->staticValue($part, $line, $structure),
                $this->splitByComma($inner)
            );
        }

        if ($tokens[0]['type'] === T_ARRAY && ($tokens[1]['value'] ?? null) === '(') {
            $inner = array_slice($tokens, 2, -1);
            return array_map(
                fn (array $part): mixed => $this->staticValue($part, $line, $structure),
                $this->splitByComma($inner)
            );
        }

        $this->fail($line, $structure, 'a static literal value (variables, constants, expressions, and function calls are not evaluated)');
    }

    private function unsupportedLiteral(int $line, string $structure): never
    {
        $this->fail($line, $structure, 'a static literal value (named constants are not evaluated)');
    }

    private function readArguments(int $openIndex): array
    {
        return $this->readArgumentsFromTokens($this->tokens, $openIndex);
    }

    private function readArgumentsFromTokens(array $tokens, int $openIndex): array
    {
        $close = $this->matchingToken($tokens, $openIndex, '(', ')');
        if ($close === null) {
            $this->fail($tokens[$openIndex]['line'], 'method call', 'a closing parenthesis');
        }

        $inner = array_slice($tokens, $openIndex + 1, $close - $openIndex - 1);
        if ($inner === []) {
            return [];
        }

        return $this->splitByComma($inner);
    }

    private function splitStatements(array $tokens): array
    {
        $statements = [];
        $current = [];
        $stack = [];
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];

        foreach ($tokens as $token) {
            $value = $token['value'];
            if ($value === ';' && $stack === []) {
                if ($current !== []) {
                    $statements[] = $current;
                    $current = [];
                }
                continue;
            }

            if (isset($pairs[$value])) {
                $stack[] = $pairs[$value];
            } elseif (in_array($value, [')', ']', '}'], true)) {
                array_pop($stack);
            }
            $current[] = $token;
        }

        if ($current !== []) {
            $this->fail($current[0]['line'], 'Blueprint callback', 'a semicolon-terminated statement');
        }

        return $statements;
    }

    private function splitByComma(array $tokens): array
    {
        $parts = [];
        $current = [];
        $stack = [];
        $pairs = ['(' => ')', '[' => ']', '{' => '}'];

        foreach ($tokens as $token) {
            $value = $token['value'];
            if ($value === ',' && $stack === []) {
                $parts[] = $current;
                $current = [];
                continue;
            }
            if (isset($pairs[$value])) {
                $stack[] = $pairs[$value];
            } elseif (in_array($value, [')', ']', '}'], true)) {
                array_pop($stack);
            }
            $current[] = $token;
        }

        if ($current !== []) {
            $parts[] = $current;
        }

        return $parts;
    }

    private function matchingToken(array $tokens, int $start, string $open, string $close): ?int
    {
        if (($tokens[$start]['value'] ?? null) !== $open) {
            return null;
        }

        $depth = 0;
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index]['value'] === $open) {
                $depth++;
            } elseif ($tokens[$index]['value'] === $close && --$depth === 0) {
                return $index;
            }
        }

        return null;
    }

    private function isSchemaCallAt(int $index): bool
    {
        if (!isset($this->tokens[$index + 3])) {
            return false;
        }

        $class = ltrim($this->tokens[$index]['value'], '\\');
        return in_array($class, $this->schemaAliases, true)
            && $this->tokens[$index + 1]['value'] === '::'
            && $this->isIdentifier($this->tokens[$index + 2])
            && $this->tokens[$index + 3]['value'] === '(';
    }

    private function hasSchemaCalls(): bool
    {
        foreach ($this->tokens as $index => $token) {
            if ($this->isSchemaCallAt($index)) {
                return true;
            }
        }

        return false;
    }

    private function upMethodTokens(): array
    {
        foreach ($this->tokens as $index => $token) {
            if (
                $token['type'] !== T_FUNCTION
                || ($this->tokens[$index + 1]['type'] ?? null) !== T_STRING
                || $this->tokens[$index + 1]['value'] !== 'up'
            ) {
                continue;
            }

            $parameterStart = $index + 2;
            while (($this->tokens[$parameterStart]['value'] ?? null) !== '(' && isset($this->tokens[$parameterStart])) {
                $parameterStart++;
            }

            $parameterEnd = $this->matchingToken($this->tokens, $parameterStart, '(', ')');
            if ($parameterEnd === null) {
                $this->fail($token['line'], 'migration up() method', 'a complete method signature');
            }

            $bodyStart = null;
            for ($cursor = $parameterEnd + 1, $count = count($this->tokens); $cursor < $count; $cursor++) {
                if ($this->tokens[$cursor]['value'] === '{') {
                    $bodyStart = $cursor;
                    break;
                }
            }
            if ($bodyStart === null) {
                $this->fail($token['line'], 'migration up() method', 'a braced method body');
            }

            $bodyEnd = $this->matchingToken($this->tokens, $bodyStart, '{', '}');
            if ($bodyEnd === null) {
                $this->fail($token['line'], 'migration up() method', 'a complete method body');
            }

            return array_slice($this->tokens, $bodyStart + 1, $bodyEnd - $bodyStart - 1);
        }

        $line = $this->tokens[0]['line'] ?? 1;
        $this->fail($line, 'migration file', 'a supported up() method; schema calls outside it are not analyzed');
    }

    private function rejectDynamicControlFlow(): void
    {
        foreach ($this->tokens as $token) {
            if (in_array($token['type'], [
                T_IF,
                T_ELSEIF,
                T_SWITCH,
                T_FOREACH,
                T_FOR,
                T_WHILE,
                T_MATCH,
            ], true)) {
                $this->fail(
                    $token['line'],
                    'conditional migration schema',
                    'the final schema because conditional or loop-based migrations are not evaluated'
                );
            }
        }
    }

    private function rejectRawSchemaSql(): void
    {
        foreach ($this->tokens as $index => $token) {
            $class = ltrim($token['value'], '\\');
            if (
                in_array($class, $this->databaseAliases, true)
                && ($this->tokens[$index + 1]['value'] ?? null) === '::'
                && in_array($this->tokens[$index + 2]['value'] ?? null, ['statement', 'unprepared'], true)
            ) {
                $this->fail(
                    $token['line'],
                    'raw database statement',
                    'the schema effects of arbitrary SQL; raw SQL is not executed or interpreted'
                );
            }
        }
    }

    private function resolveFacadeAliases(): void
    {
        foreach ($this->tokens as $index => $token) {
            if ($token['type'] !== T_USE || !isset($this->tokens[$index + 1])) {
                continue;
            }

            $import = ltrim($this->tokens[$index + 1]['value'], '\\');
            if (
                ($this->tokens[$index + 2]['value'] ?? null) === '\\'
                && ($this->tokens[$index + 3]['value'] ?? null) === '{'
                && $import === 'Illuminate\\Support\\Facades'
            ) {
                for ($cursor = $index + 4; isset($this->tokens[$cursor]); $cursor++) {
                    if ($this->tokens[$cursor]['value'] === '}') {
                        break;
                    }
                    if ($this->tokens[$cursor]['type'] !== T_STRING) {
                        continue;
                    }

                    $facade = $this->tokens[$cursor]['value'];
                    $alias = $facade;
                    if (($this->tokens[$cursor + 1]['type'] ?? null) === T_AS) {
                        $alias = $this->tokens[$cursor + 2]['value'] ?? $facade;
                    }
                    $this->addFacadeAlias($import . '\\' . $facade, $alias);
                }
                continue;
            }

            if (!str_contains($import, '\\')) {
                continue;
            }

            $alias = basename(str_replace('\\', '/', $import));
            if (($this->tokens[$index + 2]['type'] ?? null) === T_AS) {
                $alias = $this->tokens[$index + 3]['value'] ?? $alias;
            }

            $this->addFacadeAlias($import, $alias);
        }
    }

    private function addFacadeAlias(string $import, string $alias): void
    {
        if ($import === 'Illuminate\\Support\\Facades\\Schema') {
            $this->schemaAliases[] = $alias;
        } elseif ($import === 'Illuminate\\Support\\Facades\\DB') {
            $this->databaseAliases[] = $alias;
        }
    }

    private function validateUpStatements(): void
    {
        foreach ($this->splitStatements($this->tokens) as $statement) {
            if ($statement === []) {
                continue;
            }

            if (!$this->isSchemaCallAtIn($statement, 0)) {
                $this->fail(
                    $statement[0]['line'],
                    'migration up() statement',
                    'the structural effect of a statement other than a supported Schema call'
                );
            }

            $closingParenthesis = $this->matchingToken($statement, 3, '(', ')');
            if ($closingParenthesis !== count($statement) - 1) {
                $this->fail(
                    $statement[0]['line'],
                    'migration up() Schema call',
                    'the complete schema effects of the call expression'
                );
            }

            $method = $statement[2]['value'] ?? '';
            if (!in_array($method, [
                'create',
                'table',
                'drop',
                'dropIfExists',
                'rename',
                'enableForeignKeyConstraints',
                'disableForeignKeyConstraints',
            ], true)) {
                $this->fail(
                    $statement[0]['line'],
                    "Schema::{$method}",
                    'the schema effect of this Schema method'
                );
            }
        }
    }

    private function isSchemaCallAtIn(array $tokens, int $index): bool
    {
        if (!isset($tokens[$index + 3])) {
            return false;
        }

        $class = ltrim($tokens[$index]['value'], '\\');
        return in_array($class, $this->schemaAliases, true)
            && $tokens[$index + 1]['value'] === '::'
            && $this->isIdentifier($tokens[$index + 2])
            && $tokens[$index + 3]['value'] === '(';
    }

    private function isIdentifier(array $token): bool
    {
        return in_array($token['type'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true);
    }

    private function fail(int $line, string $structure, string $unknown): never
    {
        throw new MigrationAnalysisException($this->file, $line, $structure, $unknown);
    }

    private function assertArgumentCount(
        array $arguments,
        int $minimum,
        int $maximum,
        int $line,
        string $structure
    ): void {
        if (count($arguments) < $minimum || count($arguments) > $maximum) {
            $this->fail(
                $line,
                $structure,
                "between {$minimum} and {$maximum} supported literal arguments"
            );
        }
    }

    private function assertSingleBlueprintCall(array $calls, int $line, string $structure): void
    {
        if (count($calls) !== 1) {
            $this->fail($line, $structure, 'the schema effect of chained Blueprint calls after this operation');
        }
    }

    private const COLUMN_TYPES = [
        'id' => ['type' => 'bigInteger', 'parameters' => [], 'auto_increment' => true, 'unsigned' => true],
        'increments' => ['type' => 'integer', 'parameters' => [], 'auto_increment' => true, 'unsigned' => true],
        'tinyIncrements' => ['type' => 'tinyInteger', 'parameters' => [], 'auto_increment' => true, 'unsigned' => true],
        'smallIncrements' => ['type' => 'smallInteger', 'parameters' => [], 'auto_increment' => true, 'unsigned' => true],
        'mediumIncrements' => ['type' => 'mediumInteger', 'parameters' => [], 'auto_increment' => true, 'unsigned' => true],
        'bigIncrements' => ['type' => 'bigInteger', 'parameters' => [], 'auto_increment' => true, 'unsigned' => true],
        'foreignId' => ['type' => 'bigInteger', 'parameters' => [], 'unsigned' => true],
        'foreignUuid' => ['type' => 'uuid', 'parameters' => []],
        'foreignUlid' => ['type' => 'ulid', 'parameters' => []],
        'string' => ['type' => 'string', 'parameters' => ['length' => 1]],
        'char' => ['type' => 'char', 'parameters' => ['length' => 1]],
        'binary' => ['type' => 'binary', 'parameters' => ['length' => 1]],
        'integer' => ['type' => 'integer', 'parameters' => []],
        'tinyInteger' => ['type' => 'tinyInteger', 'parameters' => []],
        'smallInteger' => ['type' => 'smallInteger', 'parameters' => []],
        'mediumInteger' => ['type' => 'mediumInteger', 'parameters' => []],
        'bigInteger' => ['type' => 'bigInteger', 'parameters' => []],
        'unsignedInteger' => ['type' => 'integer', 'parameters' => [], 'unsigned' => true],
        'unsignedTinyInteger' => ['type' => 'tinyInteger', 'parameters' => [], 'unsigned' => true],
        'unsignedSmallInteger' => ['type' => 'smallInteger', 'parameters' => [], 'unsigned' => true],
        'unsignedMediumInteger' => ['type' => 'mediumInteger', 'parameters' => [], 'unsigned' => true],
        'unsignedBigInteger' => ['type' => 'bigInteger', 'parameters' => [], 'unsigned' => true],
        'float' => ['type' => 'float', 'parameters' => ['precision' => 1, 'scale' => 2]],
        'double' => ['type' => 'double', 'parameters' => ['precision' => 1, 'scale' => 2]],
        'decimal' => ['type' => 'decimal', 'parameters' => ['precision' => 1, 'scale' => 2]],
        'boolean' => ['type' => 'boolean', 'parameters' => []],
        'date' => ['type' => 'date', 'parameters' => []],
        'dateTime' => ['type' => 'dateTime', 'parameters' => ['precision' => 1]],
        'dateTimeTz' => ['type' => 'dateTimeTz', 'parameters' => ['precision' => 1]],
        'time' => ['type' => 'time', 'parameters' => ['precision' => 1]],
        'timeTz' => ['type' => 'timeTz', 'parameters' => ['precision' => 1]],
        'timestamp' => ['type' => 'timestamp', 'parameters' => ['precision' => 1]],
        'timestampTz' => ['type' => 'timestampTz', 'parameters' => ['precision' => 1]],
        'year' => ['type' => 'year', 'parameters' => []],
        'text' => ['type' => 'text', 'parameters' => []],
        'mediumText' => ['type' => 'mediumText', 'parameters' => []],
        'longText' => ['type' => 'longText', 'parameters' => []],
        'json' => ['type' => 'json', 'parameters' => []],
        'jsonb' => ['type' => 'jsonb', 'parameters' => []],
        'uuid' => ['type' => 'uuid', 'parameters' => []],
        'ulid' => ['type' => 'ulid', 'parameters' => []],
        'enum' => ['type' => 'enum', 'parameters' => [], 'enum' => true],
        'set' => ['type' => 'set', 'parameters' => [], 'enum' => true],
    ];
}
