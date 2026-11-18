<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\DatabaseSchema;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaColumn;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaTable;
use RuntimeException;

class CrudGenerator
{
    private const ELOQUENT_MANAGED_COLUMNS = [
        'created_at',
        'updated_at',
        'deleted_at',
        'remember_token',
    ];

    public function generateFromTable(
        string $projectRoot,
        string $modelClass,
        SchemaTable $table,
        DatabaseSchema $schema
    ): bool {
        $modelBasename = $this->modelBasename($modelClass);
        $primaryColumns = [];
        foreach ($table->indexes as $index) {
            if ($index->type === 'primary') {
                $primaryColumns = $index->columns;
                break;
            }
        }

        foreach ($schema->relations as $relation) {
            if ($relation->cardinality === 'N:M' && $relation->pivotTable === $table->name) {
                throw new RuntimeException(
                    "CRUD is not generated for pivot table {$table->name}."
                );
            }
        }

        if (count($primaryColumns) !== 1) {
            throw new RuntimeException(
                "CRUD for {$table->name} requires exactly one primary-key column; composite or missing keys are unsupported."
            );
        }

        $primaryKey = $primaryColumns[0];
        if (!isset($table->columns[$primaryKey])) {
            throw new RuntimeException("Primary key {$table->name}.{$primaryKey} is missing from its schema.");
        }
        $primaryColumn = $table->columns[$primaryKey];
        if (!$primaryColumn->autoIncrement && !$primaryColumn->hasDefault) {
            throw new RuntimeException(
                "CRUD for {$table->name} cannot create records with a non-generated primary key {$primaryKey}."
            );
        }
        if (isset($table->columns['created_at']) !== isset($table->columns['updated_at'])) {
            throw new RuntimeException(
                "CRUD for {$table->name} requires both Eloquent timestamp columns or neither."
            );
        }
        $this->assertIdentifier($table->name, 'table');
        foreach ($table->columns as $column) {
            $this->assertIdentifier($column->name, 'column');
        }
        foreach ($table->foreignKeys as $foreignKey) {
            if (count($foreignKey->columns) !== 1 || count($foreignKey->referencedColumns) !== 1) {
                throw new RuntimeException(
                    "CRUD for {$table->name} cannot validate composite foreign key {$foreignKey->name}."
                );
            }
            $this->assertIdentifier($foreignKey->referencedTable, 'referenced table');
            $this->assertIdentifier($foreignKey->referencedColumns[0], 'referenced column');
        }

        $fillableColumns = [];
        foreach ($table->columns as $column) {
            if (
                $column->autoIncrement
                || in_array($column->name, $primaryColumns, true)
                || in_array($column->name, self::ELOQUENT_MANAGED_COLUMNS, true)
            ) {
                continue;
            }
            $this->assertSupportedColumn($column);
            $fillableColumns[] = $column->name;
        }

        $uniqueIndexes = [];
        foreach ($table->indexes as $index) {
            if ($index->type === 'primary' || !$index->unique) {
                continue;
            }
            foreach ($index->columns as $column) {
                if (!in_array($column, $fillableColumns, true)) {
                    throw new RuntimeException(
                        "CRUD for {$table->name} cannot safely validate unique index {$index->name} "
                        . 'because it includes an excluded or Eloquent-managed column.'
                    );
                }
            }
            $uniqueIndexes[] = $index->columns;
        }

        $controllerPath = rtrim($projectRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http'
            . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'Api'
            . DIRECTORY_SEPARATOR . $modelBasename . 'Controller.php';
        $source = $this->controllerSource(
            $modelClass,
            $modelBasename,
            $table,
            $primaryKey,
            $fillableColumns,
            $uniqueIndexes,
            isset($table->columns['created_at'])
        );

        if (is_file($controllerPath)) {
            $existing = file_get_contents($controllerPath);
            if ($existing === $source) {
                return false;
            }
            throw new RuntimeException(
                "Controller {$controllerPath} already exists and is not an unchanged LRG-generated controller."
            );
        }

        $directory = dirname($controllerPath);
        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create controller directory: {$directory}");
        }
        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $exception) {
            throw new RuntimeException("Generated controller has invalid PHP: {$controllerPath}", 0, $exception);
        }

        $temporaryPath = tempnam($directory, '.lrg-controller-');
        if ($temporaryPath === false) {
            throw new RuntimeException("Could not prepare generated controller: {$controllerPath}");
        }
        try {
            $written = file_put_contents($temporaryPath, $source, LOCK_EX);
            if ($written !== strlen($source) || file_get_contents($temporaryPath) !== $source) {
                throw new RuntimeException("Could not verify generated controller: {$controllerPath}");
            }
            if (!@rename($temporaryPath, $controllerPath)) {
                throw new RuntimeException("Could not install generated controller: {$controllerPath}");
            }
        } finally {
            if (is_file($temporaryPath)) {
                @unlink($temporaryPath);
            }
        }

        return true;
    }

    /**
     * @param list<string> $fillableColumns
     * @param list<list<string>> $uniqueIndexes
     * @param bool $hasTimestamps
     */
    private function controllerSource(
        string $modelClass,
        string $modelBasename,
        SchemaTable $table,
        string $primaryKey,
        array $fillableColumns,
        array $uniqueIndexes,
        bool $hasTimestamps
    ): string {
        $modelClass = '\\' . ltrim($modelClass, '\\');
        if (!str_contains(ltrim($modelClass, '\\'), '\\')) {
            $modelClass = '\\App\\Models\\' . ltrim($modelClass, '\\');
        }
        $modelImport = ltrim($modelClass, '\\');

        $rules = [];
        foreach ($fillableColumns as $columnName) {
            $column = $table->columns[$columnName];
            $createRules = $this->baseRules($column, false);
            $updateRules = $this->baseRules($column, true);
            $rules[] = '        $rules[' . var_export($columnName, true) . '] = $updating ? '
                . var_export($updateRules, true) . ' : ' . var_export($createRules, true) . ';';
            if (in_array($column->type, ['enum', 'set'], true) && $column->enumValues !== []) {
                $rules[] = '        $rules[' . var_export($columnName, true)
                    . '][] = Rule::in(' . var_export($column->enumValues, true) . ');';
            }
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $column = $foreignKey->columns[0];
            if (!in_array($column, $fillableColumns, true)) {
                continue;
            }
            $rules[] = '        $rules[' . var_export($column, true) . '][] = Rule::exists('
                . var_export($foreignKey->referencedTable, true) . ', '
                . var_export($foreignKey->referencedColumns[0], true) . ');';
        }

        foreach ($uniqueIndexes as $columns) {
            $uniqueColumn = array_shift($columns);
            $rules[] = '        $uniqueRule = Rule::unique('
                . var_export($table->name, true) . ', '
                . var_export($uniqueColumn, true) . ');';
            $rules[] = '        if ($updating && $record !== null) {';
            $rules[] = '            $uniqueRule->ignore($record->getAttribute('
                . var_export($primaryKey, true) . '), '
                . var_export($primaryKey, true) . ');';
            $rules[] = '        }';
            foreach ($columns as $otherColumn) {
                $rules[] = '        $uniqueRule->where(function ($query) use ($request, $record): void {';
                $rules[] = '            $query->where(' . var_export($otherColumn, true)
                    . ', $request->input(' . var_export($otherColumn, true)
                    . ', $record?->getAttribute(' . var_export($otherColumn, true) . ')));';
                $rules[] = '        });';
            }
            $rules[] = '        $rules[' . var_export($uniqueColumn, true) . '][] = $uniqueRule;';
        }

        $rulesSource = $rules === [] ? '        return [];' : implode("\n", $rules) . "\n\n        return \$rules;";
        $conflictMessage = var_export('Database integrity constraint conflict.', true);
        $notFoundMessage = var_export('Resource not found.', true);
        $keyName = var_export($primaryKey, true);
        $resourceController = $modelBasename . 'Controller';
        $timestampDisable = $hasTimestamps ? '' : "            \$record->timestamps = false;\n";
        $modelCreate = "            \$record = new {$modelClass}();\n" . $timestampDisable;

        return "<?php\n\n"
            . "// LRG generated controller; do not edit this file manually.\n"
            . "namespace App\\Http\\Controllers\\Api;\n\n"
            . "use Illuminate\\Database\\QueryException;\n"
            . "use Illuminate\\Http\\Request;\n"
            . "use Illuminate\\Validation\\Rule;\n"
            . "use {$modelImport};\n\n"
            . "class {$resourceController}\n{\n"
            . "    public function index()\n    {\n"
            . "        return response()->json({$modelClass}::query()->get());\n"
            . "    }\n\n"
            . "    public function store(Request \$request)\n    {\n"
            . "        \$validated = \$request->validate(\$this->rules(\$request, false));\n"
            . "        try {\n"
            . $modelCreate
            . "            \$record->fill(\$validated);\n"
            . "            \$record->save();\n"
            . "        } catch (QueryException \$exception) {\n"
            . "            return \$this->integrityConflict(\$exception);\n"
            . "        }\n"
            . "        return response()->json(\$record, 201);\n"
            . "    }\n\n"
            . "    public function show(string \$id)\n    {\n"
            . "        \$record = {$modelClass}::query()->where({$keyName}, \$id)->first();\n"
            . "        if (\$record === null) {\n"
            . "            return response()->json(['message' => {$notFoundMessage}], 404);\n"
            . "        }\n"
            . "        return response()->json(\$record);\n"
            . "    }\n\n"
            . "    public function update(Request \$request, string \$id)\n    {\n"
            . "        \$record = {$modelClass}::query()->where({$keyName}, \$id)->first();\n"
            . "        if (\$record === null) {\n"
            . "            return response()->json(['message' => {$notFoundMessage}], 404);\n"
            . "        }\n"
            . "        \$validated = \$request->validate(\$this->rules(\$request, true, \$record));\n"
            . "        try {\n"
            . $timestampDisable
            . "            \$record->fill(\$validated);\n"
            . "            \$record->save();\n"
            . "        } catch (QueryException \$exception) {\n"
            . "            return \$this->integrityConflict(\$exception);\n"
            . "        }\n"
            . "        return response()->json(\$record);\n"
            . "    }\n\n"
            . "    public function destroy(string \$id)\n    {\n"
            . "        \$record = {$modelClass}::query()->where({$keyName}, \$id)->first();\n"
            . "        if (\$record === null) {\n"
            . "            return response()->json(['message' => {$notFoundMessage}], 404);\n"
            . "        }\n"
            . "        try {\n"
            . "            \$record->delete();\n"
            . "        } catch (QueryException \$exception) {\n"
            . "            return \$this->integrityConflict(\$exception);\n"
            . "        }\n"
            . "        return response()->noContent();\n"
            . "    }\n\n"
            . "    private function rules(Request \$request, bool \$updating, ?{$modelClass} \$record = null): array\n    {\n"
            . "        \$rules = [];\n"
            . $rulesSource . "\n"
            . "    }\n\n"
            . "    private function integrityConflict(QueryException \$exception)\n    {\n"
            . "        \$sqlState = (string) (\$exception->errorInfo[0] ?? \$exception->getCode());\n"
            . "        if (in_array(\$sqlState, ['23000', '23502', '23503', '23505', '19'], true)) {\n"
            . "            return response()->json(['message' => {$conflictMessage}], 409);\n"
            . "        }\n"
            . "        throw \$exception;\n"
            . "    }\n"
            . "}\n";
    }

    /**
     * @return list<string>
     */
    private function baseRules(SchemaColumn $column, bool $updating): array
    {
        if ($updating) {
            $rules = ['sometimes'];
            if (!$column->nullable) {
                $rules[] = 'required';
            }
        } elseif ($column->nullable) {
            $rules = ['nullable'];
        } else {
            $rules = [$column->hasDefault ? 'sometimes' : 'required'];
            if ($column->hasDefault) {
                $rules[] = 'required';
            }
        }

        $typeRules = match ($column->type) {
            'string', 'char', 'tinyText', 'text', 'mediumText', 'longText' => ['string'],
            'tinyInteger', 'smallInteger', 'mediumInteger', 'integer', 'bigInteger',
            'tinyIntegerUnsigned', 'smallIntegerUnsigned', 'mediumIntegerUnsigned',
            'integerUnsigned', 'bigIntegerUnsigned', 'year' => ['integer'],
            'float', 'double', 'decimal' => ['numeric'],
            'boolean' => ['boolean'],
            'date', 'dateTime', 'dateTimeTz', 'timestamp', 'timestampTz' => ['date'],
            'time', 'timeTz' => ['date_format:H:i:s'],
            'json', 'jsonb' => ['json'],
            'uuid' => ['uuid'],
            'ulid' => ['ulid'],
            'enum', 'set' => [],
            'binary' => throw new RuntimeException(
                "CRUD validation for binary column {$column->name} is not supported."
            ),
            default => throw new RuntimeException(
                "CRUD validation for column {$column->name} with type {$column->type} is not supported."
            ),
        };
        array_push($rules, ...$typeRules);

        if ($column->length !== null && in_array($column->type, ['string', 'char'], true)) {
            $rules[] = 'max:' . $column->length;
        }
        if ($column->unsigned && in_array($column->type, [
            'tinyInteger',
            'smallInteger',
            'mediumInteger',
            'integer',
            'bigInteger',
            'tinyIntegerUnsigned',
            'smallIntegerUnsigned',
            'mediumIntegerUnsigned',
            'integerUnsigned',
            'bigIntegerUnsigned',
            'float',
            'double',
            'decimal',
        ], true)) {
            $rules[] = 'min:0';
        }
        if ($column->type === 'decimal' && $column->precision !== null) {
            $integerDigits = $column->precision - ($column->scale ?? 0);
            if ($integerDigits < 1) {
                throw new RuntimeException("Invalid decimal precision for column {$column->name}.");
            }
            $sign = $column->unsigned ? '' : '-?';
            $fraction = ($column->scale ?? 0) > 0
                ? '(?:\\.[0-9]{1,' . $column->scale . '})?'
                : '';
            $rules[] = 'regex:/^' . $sign . '[0-9]{1,' . $integerDigits . '}' . $fraction . '$/';
        }
        return $rules;
    }

    private function assertSupportedColumn(SchemaColumn $column): void
    {
        $this->assertIdentifier($column->name, 'column');
        $this->baseRules($column, false);
    }

    private function assertIdentifier(string $identifier, string $kind): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) {
            throw new RuntimeException("CRUD does not support this {$kind} identifier: {$identifier}");
        }
    }

    private function modelBasename(string $modelClass): string
    {
        $parts = explode('\\', ltrim($modelClass, '\\'));
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) {
                throw new RuntimeException("Invalid model class name: {$modelClass}");
            }
        }

        if ($parts === [] || end($parts) === '') {
            throw new RuntimeException("Invalid model class name: {$modelClass}");
        }

        return end($parts);
    }
}
