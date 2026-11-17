<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

class SchemaIndex
{
    public function __construct(
        public string $name,
        public string $type,
        public array $columns,
        public bool $unique = false,
        public string $migrationFile = '',
        public int $migrationLine = 1,
    ) {
    }

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'columns' => $this->columns,
            'unique' => $this->unique,
        ];
    }
}
