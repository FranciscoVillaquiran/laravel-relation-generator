<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

class SchemaBuilder
{
    /**
     * @param list<array<string, mixed>> $operations
     * @param list<string> $migrationFiles
     */
    public function build(array $operations, array $migrationFiles): DatabaseSchema
    {
        $schema = new DatabaseSchema(migrationFiles: $migrationFiles);

        foreach ($operations as $operation) {
            $file = $operation['file'];
            $line = $operation['line'];
            $name = $operation['table'];

            if ($operation['operation'] === 'rename_table') {
                $this->renameTable($schema, $name, $operation['new_table'], $file, $line);
                continue;
            }

            if ($operation['operation'] === 'drop_table') {
                if (!isset($schema->tables[$name]) && !$operation['if_exists']) {
                    $this->fail($file, $line, "Schema::drop('{$name}')", "an existing table named {$name}");
                }

                unset($schema->tables[$name]);
                continue;
            }

            if ($operation['operation'] === 'create_table') {
                if (isset($schema->tables[$name])) {
                    $this->fail($file, $line, "Schema::create('{$name}')", 'a non-duplicate table definition');
                }

                $schema->tables[$name] = new SchemaTable($name);
            } elseif (!isset($schema->tables[$name])) {
                $this->fail($file, $line, "Schema::table('{$name}')", "an existing table named {$name}");
            }

            $table = $schema->tables[$name];
            foreach ($operation['statements'] as $statement) {
                $this->applyStatement($table, $statement, $file);
            }
        }

        $this->validateIndexes($schema);
        $this->validateForeignKeys($schema);
        $schema->relations = $this->relations($schema);

        return $schema;
    }

    private function applyStatement(
        SchemaTable $table,
        array $statement,
        string $file
    ): void {
        $line = $statement['line'];
        $description = $statement['description'];

        switch ($statement['operation']) {
            case 'column':
                $data = $statement['column'];
                $columnName = $data['name'];
                $autoIncrement = $data['auto_increment'];

                if ($data['change']) {
                    if (!isset($table->columns[$columnName])) {
                        $this->fail($file, $line, $description, "an existing column named {$columnName} to change");
                    }

                    $column = $table->columns[$columnName];
                    $column->type = $data['type'];
                    $column->length = $data['length'];
                    $column->precision = $data['precision'];
                    $column->scale = $data['scale'];
                    $column->unsigned = $data['unsigned'];
                    $column->autoIncrement = $data['auto_increment'];
                    $column->enumValues = $data['enum_values'];

                    foreach ($data['specified'] as $attribute) {
                        if (in_array($attribute, [
                            'name',
                            'type',
                            'length',
                            'precision',
                            'scale',
                            'unsigned',
                            'auto_increment',
                            'enum_values',
                            'change',
                            'specified',
                        ], true)) {
                            continue;
                        }

                        $property = match ($attribute) {
                            'has_default' => 'hasDefault',
                            'default_is_expression' => 'defaultIsExpression',
                            default => $this->camelCase($attribute),
                        };
                        $column->{$property} = $data[$attribute];
                    }
                } else {
                    if (isset($table->columns[$columnName])) {
                        $this->fail($file, $line, $description, "a non-duplicate column named {$columnName}");
                    }

                    unset($data['specified'], $data['change']);
                    $data['hasDefault'] = $data['has_default'];
                    $data['defaultIsExpression'] = $data['default_is_expression'];
                    $data['autoIncrement'] = $data['auto_increment'];
                    $data['enumValues'] = $data['enum_values'];
                    unset($data['has_default'], $data['default_is_expression'], $data['auto_increment'], $data['enum_values']);
                    $table->columns[$columnName] = new SchemaColumn(...$data);
                }

                if ($statement['indexes'] !== []) {
                    foreach ($statement['indexes'] as $index) {
                        $this->addIndex($table, $index, $file, $line, $description);
                    }
                }
                if ($autoIncrement) {
                    $hasPrimary = false;
                    foreach ($table->indexes as $index) {
                        if ($index->type === 'primary' && $index->columns === [$columnName]) {
                            $hasPrimary = true;
                            break;
                        }
                    }
                    if (!$hasPrimary) {
                        $this->addIndex($table, [
                            'type' => 'primary',
                            'columns' => [$columnName],
                            'name' => null,
                            'unique' => true,
                        ], $file, $line, $description);
                    }
                }

                foreach ($statement['foreign_keys'] as $foreignKey) {
                    $this->addForeignKey($table, $foreignKey, $file, $line, $description);
                }

                break;

            case 'index':
                $this->addIndex($table, $statement['index'], $file, $line, $description);
                break;

            case 'foreign_key':
                $this->addForeignKey($table, $statement['foreign_key'], $file, $line, $description);
                break;

            case 'drop_column':
                foreach ($statement['columns'] as $column) {
                    if (!isset($table->columns[$column])) {
                        $this->fail($file, $line, $description, "an existing column named {$column} to drop");
                    }

                    unset($table->columns[$column]);
                    foreach ($table->indexes as $indexName => $index) {
                        if (in_array($column, $index->columns, true)) {
                            unset($table->indexes[$indexName]);
                        }
                    }
                    foreach ($table->foreignKeys as $keyName => $foreignKey) {
                        if (in_array($column, $foreignKey->columns, true)) {
                            unset($table->foreignKeys[$keyName]);
                        }
                    }
                }
                break;

            case 'rename_column':
                [$oldName, $newName] = $statement['columns'];
                if (!isset($table->columns[$oldName]) || isset($table->columns[$newName])) {
                    $this->fail($file, $line, $description, "an existing column {$oldName} and unused name {$newName}");
                }

                $column = $table->columns[$oldName];
                unset($table->columns[$oldName]);
                $column->name = $newName;
                $table->columns[$newName] = $column;
                foreach ($table->indexes as $index) {
                    $index->columns = array_map(
                        static fn (string $name): string => $name === $oldName ? $newName : $name,
                        $index->columns
                    );
                }
                foreach ($table->foreignKeys as $foreignKey) {
                    $foreignKey->columns = array_map(
                        static fn (string $name): string => $name === $oldName ? $newName : $name,
                        $foreignKey->columns
                    );
                }
                break;

            case 'drop_index':
                $this->dropIndex($table, $statement, $file, $line, $description);
                break;

            case 'drop_foreign_key':
                $this->dropForeignKey($table, $statement, $file, $line, $description);
                break;

            case 'table_option':
                break;
        }
    }

    private function addIndex(
        SchemaTable $table,
        array $data,
        string $file,
        int $line,
        string $description
    ): void {
        if ($data['type'] === 'primary') {
            foreach ($table->indexes as $index) {
                if ($index->type === 'primary') {
                    $this->fail($file, $line, $description, 'at most one primary-key constraint per table');
                }
            }
        }

        $name = $data['name'] ?? $this->indexName($table->name, $data['columns'], $data['type']);
        if (isset($table->indexes[$name])) {
            $this->fail($file, $line, $description, "a non-duplicate index named {$name}");
        }

        $table->indexes[$name] = new SchemaIndex(
            name: $name,
            type: $data['type'],
            columns: $data['columns'],
            unique: $data['unique'],
            migrationFile: $file,
            migrationLine: $line,
        );
    }

    private function addForeignKey(
        SchemaTable $table,
        array $data,
        string $file,
        int $line,
        string $description
    ): void {
        if ($data['referenced_table'] === null || $data['referenced_columns'] === []) {
            $this->fail($file, $line, $description, 'the referenced table and referenced columns of the foreign key');
        }

        $name = $data['name'] ?? $this->indexName($table->name, $data['columns'], 'foreign');
        if (isset($table->foreignKeys[$name])) {
            $this->fail($file, $line, $description, "a non-duplicate foreign key named {$name}");
        }

        $table->foreignKeys[$name] = new SchemaForeignKey(
            name: $name,
            columns: $data['columns'],
            referencedTable: $data['referenced_table'],
            referencedColumns: $data['referenced_columns'],
            onDelete: $data['on_delete'],
            onUpdate: $data['on_update'],
            migrationFile: $file,
            migrationLine: $line,
        );
    }

    private function dropIndex(
        SchemaTable $table,
        array $statement,
        string $file,
        int $line,
        string $description
    ): void {
        $matches = [];
        foreach ($table->indexes as $name => $index) {
            if (
                ($statement['name'] !== null && $name === $statement['name'])
                || ($statement['name'] === null && $index->type === $statement['type']
                    && $index->columns === $statement['columns'])
            ) {
                $matches[] = $name;
            }
        }

        if (count($matches) !== 1) {
            $this->fail($file, $line, $description, 'exactly one existing index matching the drop instruction');
        }

        unset($table->indexes[$matches[0]]);
    }

    private function dropForeignKey(
        SchemaTable $table,
        array $statement,
        string $file,
        int $line,
        string $description
    ): void {
        $matches = [];
        foreach ($table->foreignKeys as $name => $foreignKey) {
            if (
                ($statement['name'] !== null && $name === $statement['name'])
                || ($statement['name'] === null && $foreignKey->columns === $statement['columns'])
            ) {
                $matches[] = $name;
            }
        }

        if (count($matches) !== 1) {
            $this->fail($file, $line, $description, 'exactly one existing foreign key matching the drop instruction');
        }

        unset($table->foreignKeys[$matches[0]]);
    }

    private function validateForeignKeys(DatabaseSchema $schema): void
    {
        foreach ($schema->tables as $table) {
            foreach ($table->foreignKeys as $foreignKey) {
                foreach ($foreignKey->columns as $column) {
                    if (!isset($table->columns[$column])) {
                        $this->fail(
                            $foreignKey->migrationFile,
                            $foreignKey->migrationLine,
                            "foreign key {$foreignKey->name}",
                            "the local column {$table->name}.{$column}"
                        );
                    }
                }

                $target = $schema->tables[$foreignKey->referencedTable] ?? null;
                if ($target === null) {
                    $this->fail(
                        $foreignKey->migrationFile,
                        $foreignKey->migrationLine,
                        "foreign key {$foreignKey->name}",
                        "the referenced table {$foreignKey->referencedTable}"
                    );
                }

                foreach ($foreignKey->referencedColumns as $column) {
                    if (!isset($target->columns[$column])) {
                        $this->fail(
                            $foreignKey->migrationFile,
                            $foreignKey->migrationLine,
                            "foreign key {$foreignKey->name}",
                            "the referenced column {$foreignKey->referencedTable}.{$column}"
                        );
                    }
                }

                if (count($foreignKey->columns) !== count($foreignKey->referencedColumns)) {
                    $this->fail(
                        $foreignKey->migrationFile,
                        $foreignKey->migrationLine,
                        "foreign key {$foreignKey->name}",
                        'the same number of local and referenced columns'
                    );
                }
            }
        }
    }

    private function validateIndexes(DatabaseSchema $schema): void
    {
        foreach ($schema->tables as $table) {
            foreach ($table->indexes as $index) {
                foreach ($index->columns as $column) {
                    if (!isset($table->columns[$column])) {
                        $this->fail(
                            $index->migrationFile,
                            $index->migrationLine,
                            "index {$index->name}",
                            "the indexed column {$table->name}.{$column}"
                        );
                    }
                }
            }
        }
    }

    private function relations(DatabaseSchema $schema): array
    {
        $relations = [];

        foreach ($schema->tables as $table) {
            $foreignKeys = array_values($table->foreignKeys);
            if (count($foreignKeys) !== 2) {
                continue;
            }

            if (
                $foreignKeys[0]->referencedTable === $foreignKeys[1]->referencedTable
                || $table->hasUniqueSubset($foreignKeys[0]->columns)
                || $table->hasUniqueSubset($foreignKeys[1]->columns)
                || !$table->hasUniqueColumns(array_merge(
                    $foreignKeys[0]->columns,
                    $foreignKeys[1]->columns
                ))
            ) {
                continue;
            }

            $targets = [$foreignKeys[0], $foreignKeys[1]];
            usort(
                $targets,
                static fn (SchemaForeignKey $left, SchemaForeignKey $right): int =>
                    $left->referencedTable <=> $right->referencedTable
            );
            $relations[] = new SchemaRelation(
                cardinality: 'N:M',
                sourceTable: $targets[0]->referencedTable,
                sourceColumns: $targets[0]->referencedColumns,
                targetTable: $targets[1]->referencedTable,
                targetColumns: $targets[1]->referencedColumns,
                pivotTable: $table->name,
            );
        }

        foreach ($schema->tables as $table) {
            foreach ($table->foreignKeys as $foreignKey) {
                $cardinality = $table->hasUniqueSubset($foreignKey->columns)
                    ? '1:1'
                    : '1:N';

                $relations[] = new SchemaRelation(
                    cardinality: $cardinality,
                    sourceTable: $foreignKey->referencedTable,
                    sourceColumns: $foreignKey->referencedColumns,
                    targetTable: $table->name,
                    targetColumns: $foreignKey->columns,
                    foreignKey: $foreignKey->name,
                    foreignKeyTable: $table->name,
                    foreignKeyColumns: $foreignKey->columns,
                    referencedTable: $foreignKey->referencedTable,
                    referencedColumns: $foreignKey->referencedColumns,
                );
            }
        }

        return $relations;
    }

    private function renameTable(
        DatabaseSchema $schema,
        string $oldName,
        string $newName,
        string $file,
        int $line
    ): void {
        if (!isset($schema->tables[$oldName]) || isset($schema->tables[$newName])) {
            $this->fail($file, $line, "Schema::rename('{$oldName}', '{$newName}')", 'an existing source and unused destination table');
        }

        $table = $schema->tables[$oldName];
        unset($schema->tables[$oldName]);
        $table->name = $newName;
        $schema->tables[$newName] = $table;

        foreach ($schema->tables as $otherTable) {
            foreach ($otherTable->foreignKeys as $foreignKey) {
                if ($foreignKey->referencedTable === $oldName) {
                    $foreignKey->referencedTable = $newName;
                }
            }
        }
    }

    private function indexName(string $table, array $columns, string $type): string
    {
        $suffix = match ($type) {
            'primary' => 'primary',
            'unique' => 'unique',
            'foreign' => 'foreign',
            'fullText' => 'fulltext',
            'spatialIndex' => 'spatialindex',
            default => 'index',
        };
        $name = $table . '_' . implode('_', $columns) . '_' . $suffix;

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private function fail(string $file, int $line, string $structure, string $unknown): never
    {
        throw new MigrationAnalysisException($file, $line, $structure, $unknown);
    }
}
