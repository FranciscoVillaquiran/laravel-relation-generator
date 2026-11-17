<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

class SchemaTable
{
    /**
     * @param array<string, SchemaColumn> $columns
     * @param array<string, SchemaIndex> $indexes
     * @param array<string, SchemaForeignKey> $foreignKeys
     */
    public function __construct(
        public string $name,
        public array $columns = [],
        public array $indexes = [],
        public array $foreignKeys = [],
    ) {
    }

    public function hasUniqueColumns(array $columns): bool
    {
        $columns = array_values($columns);
        sort($columns);

        foreach ($this->indexes as $index) {
            if (!$index->unique && $index->type !== 'primary') {
                continue;
            }

            $indexedColumns = $index->columns;
            sort($indexedColumns);

            if ($indexedColumns === $columns) {
                return true;
            }
        }

        return false;
    }

    public function hasUniqueSubset(array $columns): bool
    {
        $columns = array_values($columns);

        foreach ($this->indexes as $index) {
            if (!$index->unique && $index->type !== 'primary') {
                continue;
            }

            if (array_diff($index->columns, $columns) === []) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => array_map(
                static fn (SchemaColumn $column): array => $column->toArray(),
                array_values($this->columns)
            ),
            'indexes' => array_map(
                static fn (SchemaIndex $index): array => $index->toArray(),
                array_values($this->indexes)
            ),
            'foreign_keys' => array_map(
                static fn (SchemaForeignKey $key): array => $key->toArray(),
                array_values($this->foreignKeys)
            ),
        ];
    }
}
