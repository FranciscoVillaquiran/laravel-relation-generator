<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\DatabaseSchema;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaForeignKey;
use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaRelation;
use Franciscovillaquiran\LaravelRelationGenerator\Relations\RelationDefinition;
use Illuminate\Support\Str;

class RelationshipGenerator
{
    public function relationNames(RelationDefinition $definition): array
    {
        $modelA = Str::camel($this->modelBasename($definition->modelA));
        $modelB = Str::camel($this->modelBasename($definition->modelB));

        if ($definition->cardinality === '1:1') {
            return [
                'relationA' => $modelB,
                'relationB' => $modelA,
            ];
        }

        if ($definition->cardinality === '1:N') {
            return [
                'relationA' => Str::plural($modelB),
                'relationB' => $modelA,
            ];
        }

        if ($definition->cardinality === 'N:M') {
            return [
                'relationA' => Str::plural($modelB),
                'relationB' => Str::plural($modelA),
            ];
        }

        throw new \RuntimeException(
            "Cardinality {$definition->cardinality} is not implemented yet."
        );
    }

    /**
     * Generate both relationship methods from the parsed migration schema.
     *
     * @return array{relationA: string, relationB: string}
     */
    public function generateFromSchema(
        RelationDefinition $definition,
        DatabaseSchema $schema,
        string $modelsPath
    ): array {
        [$modelAPath, $modelBPath] = $this->modelPaths($definition, $modelsPath);
        $tableA = $definition->modelATable ?? $this->tableForModel($definition->modelA);
        $tableB = $definition->modelBTable ?? $this->tableForModel($definition->modelB);

        if ($tableA === $tableB) {
            throw new \InvalidArgumentException('A structural relationship requires two distinct model tables.');
        }
        if ($schema->table($tableA) === null || $schema->table($tableB) === null) {
            throw new \RuntimeException("The migration schema does not contain both {$tableA} and {$tableB} tables.");
        }

        $relation = $this->resolveSchemaRelation($definition, $schema, $tableA, $tableB);
        [$methodA, $methodB, $names] = $this->buildSchemaMethods(
            $definition,
            $relation,
            $schema,
            $tableA,
            $tableB
        );

        $editor = new ModelSourceEditor();
        $updatedModelA = $editor->prepareMethods($modelAPath, [$methodA], $this->modelBasename($definition->modelA));
        $updatedModelB = $editor->prepareMethods($modelBPath, [$methodB], $this->modelBasename($definition->modelB));
        if ($updatedModelA !== null) {
            $editor->writePrepared($modelAPath, $updatedModelA);
        }
        if ($updatedModelB !== null) {
            $editor->writePrepared($modelBPath, $updatedModelB);
        }

        return [
            'relationA' => $names['relationA'],
            'relationB' => $names['relationB'],
        ];
    }

    public function generate(RelationDefinition $definition, string $modelsPath): array
    {
        if ($definition->cardinality === '1:1') {
            return $this->generateOneToOne($definition, $modelsPath);
        }

        if ($definition->cardinality === '1:N') {
            return $this->generateOneToMany($definition, $modelsPath);
        }

        if ($definition->cardinality === 'N:M') {
            return $this->generateManyToMany($definition, $modelsPath);
        }

        throw new \RuntimeException(
            "Cardinality {$definition->cardinality} is not implemented yet."
        );
    }

    private function generateOneToOne(
        RelationDefinition $definition,
        string $modelsPath
    ): array {
        [$modelAPath, $modelBPath] = $this->modelPaths($definition, $modelsPath);

        $relations = $this->relationNames($definition);
        $relationA = $relations['relationA'];
        $relationB = $relations['relationB'];

        if ($this->foreignTableIs($definition, $definition->modelB)) {
            $methodA = $this->buildHasOneMethod($relationA, $this->classReference($definition->modelB));
            $methodB = $this->buildBelongsToMethod($relationB, $this->classReference($definition->modelA));
        } else {
            $methodA = $this->buildBelongsToMethod($relationA, $this->classReference($definition->modelB));
            $methodB = $this->buildHasOneMethod($relationB, $this->classReference($definition->modelA));
        }

        $this->addMethod($modelAPath, $methodA);
        $this->addMethod($modelBPath, $methodB);

        return [
            'relationA' => $relationA,
            'relationB' => $relationB,
        ];
    }

    private function generateOneToMany(
        RelationDefinition $definition,
        string $modelsPath
    ): array {
        $modelA = $definition->modelA;
        $modelB = $definition->modelB;

        [$modelAPath, $modelBPath] = $this->modelPaths($definition, $modelsPath);

        $relations = $this->relationNames($definition);
        $relationA = $relations['relationA'];
        $relationB = $relations['relationB'];

        if ($this->foreignTableIs($definition, $modelB)) {
            $methodA = $this->buildHasManyMethod($relationA, $this->classReference($modelB));
            $methodB = $this->buildBelongsToMethod($relationB, $this->classReference($modelA));
        } else {
            $methodA = $this->buildBelongsToMethod($relationA, $this->classReference($modelB));
            $methodB = $this->buildHasManyMethod($relationB, $this->classReference($modelA));
        }

        $this->addMethod($modelAPath, $methodA);
        $this->addMethod($modelBPath, $methodB);

        return [
            'relationA' => $relationA,
            'relationB' => $relationB,
        ];
    }

    private function generateManyToMany(
        RelationDefinition $definition,
        string $modelsPath
    ): array {
        [$modelAPath, $modelBPath] = $this->modelPaths($definition, $modelsPath);

        if (!$definition->pivotTable) {
            throw new \InvalidArgumentException(
                'A pivot table is required for N:M relationships.'
            );
        }

        $relations = $this->relationNames($definition);
        $relationA = $relations['relationA'];
        $relationB = $relations['relationB'];

        $this->addMethod(
            $modelAPath,
            $this->buildBelongsToManyMethod(
                $relationA,
                $this->classReference($definition->modelB),
                $definition->pivotTable
            )
        );

        $this->addMethod(
            $modelBPath,
            $this->buildBelongsToManyMethod(
                $relationB,
                $this->classReference($definition->modelA),
                $definition->pivotTable
            )
        );

        return [
            'relationA' => $relationA,
            'relationB' => $relationB,
        ];
    }

    private function modelPaths(
        RelationDefinition $definition,
        string $modelsPath
    ): array {
        $paths = [
            $modelsPath . DIRECTORY_SEPARATOR . $this->modelBasename($definition->modelA) . '.php',
            $modelsPath . DIRECTORY_SEPARATOR . $this->modelBasename($definition->modelB) . '.php',
        ];

        foreach ($paths as $index => $path) {
            if (!file_exists($path)) {
                $model = $index === 0 ? $definition->modelA : $definition->modelB;

                throw new \RuntimeException("Model {$model} not found.");
            }
        }

        return $paths;
    }

    private function foreignTableIs(
        RelationDefinition $definition,
        string $model
    ): bool {
        $foreignTable = trim((string) $definition->foreignTable);
        $modelTable = $this->tableForModel($model);

        return $foreignTable === $model || $foreignTable === $modelTable;
    }

    private function resolveSchemaRelation(
        RelationDefinition $definition,
        DatabaseSchema $schema,
        string $tableA,
        string $tableB
    ): SchemaRelation {
        $candidates = array_values(array_filter(
            $schema->relations,
            function (SchemaRelation $relation) use ($definition, $tableA, $tableB): bool {
                if ($relation->cardinality !== $definition->cardinality) {
                    return false;
                }

                if ($definition->cardinality === 'N:M') {
                    return (
                        ($relation->sourceTable === $tableA && $relation->targetTable === $tableB)
                        || ($relation->sourceTable === $tableB && $relation->targetTable === $tableA)
                    ) && ($definition->pivotTable === null || $relation->pivotTable === $definition->pivotTable);
                }

                return (
                    $relation->foreignKeyTable === $tableA
                    && $relation->referencedTable === $tableB
                ) || (
                    $relation->foreignKeyTable === $tableB
                    && $relation->referencedTable === $tableA
                );
            }
        ));

        if (count($candidates) > 1) {
            $keys = array_map(
                static fn (SchemaRelation $relation): string =>
                    $relation->pivotTable ?? ($relation->foreignKeyTable . '.' . $relation->foreignKey),
                $candidates
            );
            throw new \RuntimeException(
                'The migration schema has multiple possible relationships between '
                . "{$tableA} and {$tableB}: " . implode(', ', $keys) . '.'
            );
        }

        if ($candidates === []) {
            $available = array_values(array_unique(array_map(
                static fn (SchemaRelation $relation): string => $relation->cardinality,
                array_filter(
                    $schema->relations,
                    static fn (SchemaRelation $relation): bool =>
                        (
                            ($relation->foreignKeyTable === $tableA && $relation->referencedTable === $tableB)
                            || ($relation->foreignKeyTable === $tableB && $relation->referencedTable === $tableA)
                            || (
                                ($relation->sourceTable === $tableA && $relation->targetTable === $tableB)
                                || ($relation->sourceTable === $tableB && $relation->targetTable === $tableA)
                            )
                        )
                )
            )));
            $detail = $available === []
                ? 'no matching foreign-key structure'
                : 'available cardinality: ' . implode(', ', $available);
            if ($definition->cardinality === 'N:M' && !in_array('N:M', $available, true)) {
                $detail = 'no recognized pivot table; expected exactly two foreign keys to the requested '
                    . 'tables and either a unique constraint on the key pair or Laravel\'s conventional pivot name';
            }
            throw new \RuntimeException(
                "Could not resolve a {$definition->cardinality} relationship between {$tableA} and {$tableB}; {$detail}."
            );
        }

        $relation = $candidates[0];
        if ($definition->cardinality === 'N:M' && $definition->pivotTable !== null) {
            $pivotMatches = array_values(array_filter(
                $schema->relations,
                static fn (SchemaRelation $candidate): bool =>
                    $candidate->cardinality === 'N:M'
                    && $candidate->pivotTable === $definition->pivotTable
                    && (
                        ($candidate->sourceTable === $tableA && $candidate->targetTable === $tableB)
                        || ($candidate->sourceTable === $tableB && $candidate->targetTable === $tableA)
                    )
            ));
            if (count($pivotMatches) !== 1) {
                throw new \RuntimeException(
                    "Pivot table {$definition->pivotTable} does not uniquely connect {$tableA} and {$tableB}."
                );
            }
            $relation = $pivotMatches[0];
        }

        return $relation;
    }

    /**
     * @return array{0: string, 1: string, 2: array{relationA: string, relationB: string}}
     */
    private function buildSchemaMethods(
        RelationDefinition $definition,
        SchemaRelation $relation,
        DatabaseSchema $schema,
        string $tableA,
        string $tableB
    ): array {
        $modelA = $this->classReference($definition->modelA);
        $modelB = $this->classReference($definition->modelB);

        if ($definition->cardinality === 'N:M') {
            $pivotTable = $schema->table((string) $relation->pivotTable);
            if ($pivotTable === null) {
                throw new \RuntimeException("Pivot table {$relation->pivotTable} is missing from the migration schema.");
            }

            $foreignKeys = array_values(array_filter(
                $pivotTable->foreignKeys,
                static fn (SchemaForeignKey $foreignKey): bool =>
                    in_array($foreignKey->referencedTable, [$tableA, $tableB], true)
            ));
            if (count($foreignKeys) !== 2) {
                throw new \RuntimeException(
                    "Pivot table {$pivotTable->name} must have exactly two foreign keys to {$tableA} and {$tableB}."
                );
            }

            $foreignA = $this->singleColumnForeignKey($foreignKeys, $tableA, $pivotTable->name);
            $foreignB = $this->singleColumnForeignKey($foreignKeys, $tableB, $pivotTable->name);
            $names = [
                'relationA' => Str::plural(Str::camel($this->modelBasename($definition->modelB))),
                'relationB' => Str::plural(Str::camel($this->modelBasename($definition->modelA))),
            ];

            return [
                $this->buildBelongsToManyMethod(
                    $names['relationA'],
                    $modelB,
                    $pivotTable->name,
                    $foreignA->columns[0],
                    $foreignB->columns[0],
                    $foreignA->referencedColumns[0],
                    $foreignB->referencedColumns[0]
                ),
                $this->buildBelongsToManyMethod(
                    $names['relationB'],
                    $modelA,
                    $pivotTable->name,
                    $foreignB->columns[0],
                    $foreignA->columns[0],
                    $foreignB->referencedColumns[0],
                    $foreignA->referencedColumns[0]
                ),
                $names,
            ];
        }

        $foreignKeyTable = (string) $relation->foreignKeyTable;
        $referencedTable = (string) $relation->referencedTable;
        $foreignTable = $schema->table($foreignKeyTable);
        $foreignKey = $foreignTable?->foreignKeys[$relation->foreignKey ?? ''] ?? null;
        if (!$foreignKey instanceof SchemaForeignKey) {
            throw new \RuntimeException("Foreign key {$relation->foreignKey} could not be resolved.");
        }
        if (count($foreignKey->columns) !== 1 || count($foreignKey->referencedColumns) !== 1) {
            throw new \RuntimeException(
                "Eloquent cannot safely generate a relationship from composite foreign key {$foreignKey->name}."
            );
        }
        if ($definition->cardinality === '1:1' && !$foreignTable->hasUniqueSubset($foreignKey->columns)) {
            throw new \RuntimeException(
                "One-to-one relationship {$foreignTable->name}.{$foreignKey->columns[0]} requires a unique constraint."
            );
        }

        $names = [];
        if ($foreignKeyTable === $tableB) {
            $names = [
                'relationA' => $definition->cardinality === '1:N'
                    ? Str::plural(Str::camel($this->modelBasename($definition->modelB)))
                    : Str::camel($this->modelBasename($definition->modelB)),
                'relationB' => Str::camel($this->modelBasename($definition->modelA)),
            ];
            $methodA = $definition->cardinality === '1:1'
                ? $this->buildHasOneMethod($names['relationA'], $modelB, $foreignKey->columns[0], $foreignKey->referencedColumns[0])
                : $this->buildHasManyMethod($names['relationA'], $modelB, $foreignKey->columns[0], $foreignKey->referencedColumns[0]);
            $methodB = $this->buildBelongsToMethod($names['relationB'], $modelA, $foreignKey->columns[0], $foreignKey->referencedColumns[0]);
        } elseif ($foreignKeyTable === $tableA) {
            $names = [
                'relationA' => Str::camel($this->modelBasename($definition->modelB)),
                'relationB' => $definition->cardinality === '1:N'
                    ? Str::plural(Str::camel($this->modelBasename($definition->modelA)))
                    : Str::camel($this->modelBasename($definition->modelA)),
            ];
            $methodA = $this->buildBelongsToMethod($names['relationA'], $modelB, $foreignKey->columns[0], $foreignKey->referencedColumns[0]);
            $methodB = $definition->cardinality === '1:1'
                ? $this->buildHasOneMethod($names['relationB'], $modelA, $foreignKey->columns[0], $foreignKey->referencedColumns[0])
                : $this->buildHasManyMethod($names['relationB'], $modelA, $foreignKey->columns[0], $foreignKey->referencedColumns[0]);
        } else {
            throw new \RuntimeException("Foreign key relation does not connect {$tableA} and {$tableB}.");
        }

        return [$methodA, $methodB, $names];
    }

    /**
     * @param list<SchemaForeignKey> $foreignKeys
     */
    private function singleColumnForeignKey(array $foreignKeys, string $table, string $pivotTable): SchemaForeignKey
    {
        $matches = array_values(array_filter(
            $foreignKeys,
            static fn (SchemaForeignKey $foreignKey): bool => $foreignKey->referencedTable === $table
        ));
        if (count($matches) !== 1) {
            throw new \RuntimeException(
                "Pivot table {$pivotTable} must have exactly one foreign key to {$table}."
            );
        }
        if (count($matches[0]->columns) !== 1 || count($matches[0]->referencedColumns) !== 1) {
            throw new \RuntimeException(
                "Eloquent cannot safely generate a pivot relationship from composite foreign key {$matches[0]->name}."
            );
        }

        return $matches[0];
    }

    private function tableForModel(string $model): string
    {
        return Str::plural(Str::snake($this->modelBasename($model)));
    }

    private function modelBasename(string $model): string
    {
        $parts = explode('\\', ltrim($model, '\\'));
        foreach ($parts as $part) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) {
                throw new \InvalidArgumentException("Invalid model class name: {$model}");
            }
        }

        return end($parts);
    }

    private function classReference(string $model): string
    {
        if (str_contains($model, '\\')) {
            return '\\' . ltrim($model, '\\') . '::class';
        }

        return '\\App\\Models\\' . $model . '::class';
    }

    private function buildHasManyMethod(
        string $relationName,
        string $relatedModel,
        ?string $foreignKey = null,
        ?string $localKey = null
    ): string {
        $arguments = [$this->relationshipModelArgument($relatedModel)];
        if ($foreignKey !== null) {
            $arguments[] = var_export($foreignKey, true);
            $arguments[] = var_export($localKey, true);
        }

        return <<<PHP
    
    public function {$relationName}()
    {
        return \$this->hasMany({$arguments[0]}{$this->methodArguments($arguments, 1)});
    }
PHP;
    }

    private function buildHasOneMethod(
        string $relationName,
        string $relatedModel,
        ?string $foreignKey = null,
        ?string $localKey = null
    ): string {
        $arguments = [$this->relationshipModelArgument($relatedModel)];
        if ($foreignKey !== null) {
            $arguments[] = var_export($foreignKey, true);
            $arguments[] = var_export($localKey, true);
        }

        return <<<PHP

    public function {$relationName}()
    {
        return \$this->hasOne({$arguments[0]}{$this->methodArguments($arguments, 1)});
    }
PHP;
    }

    private function buildBelongsToManyMethod(
        string $relationName,
        string $relatedModel,
        string $pivotTable,
        ?string $foreignPivotKey = null,
        ?string $relatedPivotKey = null,
        ?string $parentKey = null,
        ?string $relatedKey = null
    ): string {
        $arguments = [$this->relationshipModelArgument($relatedModel), var_export($pivotTable, true)];
        if ($foreignPivotKey !== null) {
            array_push(
                $arguments,
                var_export($foreignPivotKey, true),
                var_export($relatedPivotKey, true),
                var_export($parentKey, true),
                var_export($relatedKey, true)
            );
        }

        return <<<PHP

    public function {$relationName}()
    {
        return \$this->belongsToMany({$arguments[0]}{$this->methodArguments($arguments, 1)});
    }
PHP;
    }

    private function buildBelongsToMethod(
        string $relationName,
        string $relatedModel,
        ?string $foreignKey = null,
        ?string $ownerKey = null
    ): string {
        $arguments = [$this->relationshipModelArgument($relatedModel)];
        if ($foreignKey !== null) {
            $arguments[] = var_export($foreignKey, true);
            $arguments[] = var_export($ownerKey, true);
        }

        return <<<PHP

    public function {$relationName}()
    {
        return \$this->belongsTo({$arguments[0]}{$this->methodArguments($arguments, 1)});
    }
PHP;

    }

    /**
     * @param list<string> $arguments
     */
    private function methodArguments(array $arguments, int $offset): string
    {
        return $offset < count($arguments) ? ', ' . implode(', ', array_slice($arguments, $offset)) : '';
    }

    private function relationshipModelArgument(string $model): string
    {
        return str_ends_with($model, '::class')
            ? $model
            : var_export('App\\Models\\' . $model, true);
    }

    private function addMethod(
        string $modelPath,
        string $method
    ): void {
        (new ModelSourceEditor())->addMethods(
            $modelPath,
            [$method],
            $this->modelBasename($this->modelNameFromPath($modelPath))
        );
    }

    private function modelNameFromPath(string $modelPath): string
    {
        return pathinfo($modelPath, PATHINFO_FILENAME);
    }
}