<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

class DatabaseSchema
{
    /**
     * @param array<string, SchemaTable> $tables
     * @param list<SchemaRelation> $relations
     * @param list<string> $migrationFiles
     */
    public function __construct(
        public array $tables = [],
        public array $relations = [],
        public array $migrationFiles = [],
    ) {
    }

    public function table(string $name): ?SchemaTable
    {
        return $this->tables[$name] ?? null;
    }

    public function toArray(): array
    {
        return [
            'tables' => array_map(
                static fn (SchemaTable $table): array => $table->toArray(),
                $this->tables
            ),
            'relations' => array_map(
                static fn (SchemaRelation $relation): array => $relation->toArray(),
                $this->relations
            ),
            'migration_files' => $this->migrationFiles,
        ];
    }
}
