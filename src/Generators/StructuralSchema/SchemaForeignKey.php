<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

class SchemaForeignKey
{
    public function __construct(
        public string $name,
        public array $columns,
        public string $referencedTable,
        public array $referencedColumns,
        public ?string $onDelete = null,
        public ?string $onUpdate = null,
        public string $migrationFile = '',
        public int $migrationLine = 1,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'columns' => $this->columns,
            'referenced_table' => $this->referencedTable,
            'referenced_columns' => $this->referencedColumns,
            'on_delete' => $this->onDelete,
            'on_update' => $this->onUpdate,
        ];
    }
}
