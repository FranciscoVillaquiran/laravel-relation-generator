<?php

namespace Franciscovillaquiran\LaravelRelationGenerator\Generators\StructuralSchema;

class SchemaRelation
{
    public function __construct(
        public string $cardinality,
        public string $sourceTable,
        public array $sourceColumns,
        public string $targetTable,
        public array $targetColumns,
        public ?string $foreignKey = null,
        public ?string $pivotTable = null,
        public ?string $foreignKeyTable = null,
        public array $foreignKeyColumns = [],
        public ?string $referencedTable = null,
        public array $referencedColumns = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'cardinality' => $this->cardinality,
            'source_table' => $this->sourceTable,
            'source_columns' => $this->sourceColumns,
            'target_table' => $this->targetTable,
            'target_columns' => $this->targetColumns,
            'foreign_key' => $this->foreignKey,
            'pivot_table' => $this->pivotTable,
            'foreign_key_table' => $this->foreignKeyTable,
            'foreign_key_columns' => $this->foreignKeyColumns,
            'referenced_table' => $this->referencedTable,
            'referenced_columns' => $this->referencedColumns,
        ];
    }
}
