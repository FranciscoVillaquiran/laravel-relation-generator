<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators;

use Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema\SchemaTable;
use RuntimeException;

class FillableGenerator
{
    /**
     * Preserve the legacy list-based API while safely merging static manual fillable entries.
     *
     * @param list<string> $columns
     */
    public function generate(string $modelPath, array $columns): void
    {
        $this->assertModelExists($modelPath);
        $this->assertColumnNames($columns);

        (new ModelSourceEditor())->updateFillable(
            $modelPath,
            $this->filterColumns($columns),
            pathinfo($modelPath, PATHINFO_FILENAME)
        );
    }

    public function generateFromTable(string $modelPath, SchemaTable $table, ?string $modelClass = null): void
    {
        $this->assertModelExists($modelPath);

        $primaryColumns = [];
        foreach ($table->indexes as $index) {
            if ($index->type === 'primary') {
                array_push($primaryColumns, ...$index->columns);
            }
        }

        $columns = [];
        foreach ($table->columns as $column) {
            if (
                $column->autoIncrement
                || in_array($column->name, $primaryColumns, true)
                || in_array($column->name, [
                    'created_at',
                    'updated_at',
                    'deleted_at',
                    'remember_token',
                ], true)
            ) {
                continue;
            }

            $columns[] = $column->name;
        }

        (new ModelSourceEditor())->updateFillable(
            $modelPath,
            $this->filterColumns($columns),
            $modelClass ?? pathinfo($modelPath, PATHINFO_FILENAME)
        );
    }

    private function assertModelExists(string $modelPath): void
    {
        if (!is_file($modelPath)) {
            throw new RuntimeException("Model not found: {$modelPath}");
        }
    }

    /**
     * @param list<mixed> $columns
     */
    private function assertColumnNames(array $columns): void
    {
        foreach ($columns as $column) {
            if (!is_string($column) || $column === '') {
                throw new RuntimeException('Fillable column names must be non-empty strings.');
            }
        }
    }

    /**
     * @param list<string> $columns
     * @return list<string>
     */
    private function filterColumns(array $columns): array
    {
        return array_values(array_unique(array_filter(
            $columns,
            static fn (string $column): bool => !in_array($column, [
                'id',
                'created_at',
                'updated_at',
                'deleted_at',
                'remember_token',
            ], true)
        )));
    }
}
